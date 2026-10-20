from django.urls import path

from . import views

app_name = "t5web"

urlpatterns = [
    path("managers/", views.managers, name="managers"),
    path("roles/", views.roles, name="roles"),
]
