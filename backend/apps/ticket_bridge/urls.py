from django.urls import path

from . import views

app_name = 'bridge'

urlpatterns = [
    path('tickets/', views.my_tickets, name='my_tickets'),
    path('jump/', views.jump, name='jump'),
]
