from django.urls import path

from . import views

app_name = 'workflow'

urlpatterns = [
    path('', views.workflow_list, name='list'),
    path('<int:pk>/', views.workflow_detail, name='detail'),
    path('instance/<int:pk>/apply/', views.instance_apply, name='instance_apply'),
    path('bind/letter/<int:letter_pk>/', views.bind_letter, name='bind_letter'),
]
