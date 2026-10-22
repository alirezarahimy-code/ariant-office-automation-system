from django.contrib.auth import logout
from django.shortcuts import redirect
from django.urls import reverse

from .models import ProgramAccess
from .services import audit, has_program_access


class T5ProgramAccessMiddleware:
    """Apply T5's explicit application grant to every authenticated request.

    This makes access revocation effective for already-open sessions, not just
    at the next password login. Accounts without a stored grant remain on the
    legacy T5 ProgramUser fallback until migrated by the T5 manager screen.
    """

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        user = getattr(request, "user", None)
        if user and user.is_authenticated and not user.is_superuser:
            grant_exists = ProgramAccess.objects.filter(
                user=user, program=ProgramAccess.Program.T5WEB
            ).exists()
            # Enforce explicit grants and the legacy ProgramUser fallback on
            # every request so revocation also terminates existing sessions.
            if ((grant_exists or user.employee_id)
                    and not has_program_access(user, ProgramAccess.Program.T5WEB)):
                audit(
                    user, "program_access_revoked", model="T5Web",
                    detail=request.path,
                )
                logout(request)
                return redirect("core:login")
        if (user and user.is_authenticated and user.must_change_password
                and request.path_info not in {
                    reverse("core:change_pass"), reverse("core:logout"),
                }
                and not request.path_info.startswith("/static/")):
            return redirect(f"{reverse('core:change_pass')}?Message=1")
        return self.get_response(request)
