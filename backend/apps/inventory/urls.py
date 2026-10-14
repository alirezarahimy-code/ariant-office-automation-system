from django.urls import path

from . import views

app_name = 'inventory'

urlpatterns = [
    path('', views.item_list, name='list'),
    path('new/', views.move_new, name='move_new'),
    path('balance/', views.balance, name='balance'),
    path('count/apply/', views.count_apply, name='count_apply'),
    path('<int:pk>/', views.item_detail, name='detail'),
]
