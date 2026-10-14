from django.urls import path

from . import views

app_name = 'registry'

urlpatterns = [
    path('', views.platform_view, name='platform'),
]
