from django.urls import path

from . import views

app_name = 'archive'

urlpatterns = [
    path('', views.archive_list, name='list'),
    path('box/<int:pk>/', views.box_detail, name='box_detail'),
    path('box/<int:box_pk>/new-folder/', views.folder_new, name='folder_new'),
    path('folder/<int:pk>/', views.folder_detail, name='folder_detail'),
    path('letter/<int:letter_pk>/archive/', views.archive_letter, name='archive_letter'),
    path('folder/<int:folder_pk>/disposal/', views.disposal_request, name='disposal_request'),
    path('disposal/<int:pk>/decide/', views.disposal_decide, name='disposal_decide'),
    path('disposal/<int:pk>/execute/', views.disposal_execute, name='disposal_execute'),
]
