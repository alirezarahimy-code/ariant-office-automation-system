from django.urls import path

from . import views

app_name = 'inventory'

urlpatterns = [
    path('', views.item_list, name='list'),
    path('new/', views.move_new, name='move_new'),
    path('balance/', views.balance, name='balance'),
    path('value/', views.value_report, name='value'),
    path('transfer/', views.transfer_new, name='transfer'),
    path('count/apply/', views.count_apply, name='count_apply'),
    path('move/<int:pk>/print/', views.move_print, name='move_print'),
    path('<int:pk>/', views.item_detail, name='detail'),
    path('<int:pk>/movement/', views.movement, name='movement'),
]
