from django.urls import path

from . import views

app_name = 'commerce'

urlpatterns = [
    path('', views.purchase_list, name='list'),
    path('new/', views.purchase_new, name='new'),
    path('contracts/', views.contract_list, name='contract_list'),
    path('contracts/new/', views.contract_new, name='contract_new'),
    path('<int:pk>/', views.purchase_detail, name='detail'),
    path('<int:pk>/submit/', views.purchase_submit, name='submit'),
    path('<int:pk>/decide/', views.purchase_decide, name='decide'),
    path('<int:pk>/inquiry/', views.inquiry_add, name='inquiry_add'),
    path('<int:pk>/inquiry/<int:inquiry_pk>/select/', views.inquiry_select,
         name='inquiry_select'),
]
