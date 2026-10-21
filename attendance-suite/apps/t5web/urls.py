from django.urls import path

from . import views

app_name = "t5web"

urlpatterns = [
    path("managers/", views.managers, name="managers"),
    path("change-lock/", views.change_lock, name="change_lock"),
    path("change-lock/check/", views.change_lock_check, name="change_lock_check"),
    path("roles/", views.roles, name="roles"),
]
