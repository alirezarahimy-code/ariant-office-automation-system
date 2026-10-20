from django.contrib.auth import logout
from django.shortcuts import redirect

from .models import ProgramAccess
from .services import audit, has_program_access


class T5ProgramAccessMiddleware:
    """Apply T5's explicit application grant to every authenticated request.

    This makes access revocation effective for already-open sessions, not just
    at the next password login. Accounts without a stored grant remain on the
    legacy T5 WebUser fallback until migrated by the T5 manager screen.
    """

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        user = getattr(request, "user", None)
        if user and user.is_authenticated and not user.is_superuser:
            grant = ProgramAccess.objects.filter(
                user=user, program=ProgramAccess.Program.T5WEB
            ).only("is_allowed").first()
            if grant is not None and not has_program_access(user, ProgramAccess.Program.T5WEB):
                audit(
                    user, "program_access_revoked", model="T5Web",
                    detail=request.path,
                )
                logout(request)
                return redirect("core:login")
        return self.get_response(request)
