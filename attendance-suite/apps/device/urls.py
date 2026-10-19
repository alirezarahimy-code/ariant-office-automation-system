from django.urls import path
from . import views

app_name = 'device'

urlpatterns = [
    path('', views.index, name='index'),
    path('add/', views.device_add, name='device_add'),
    path('raw/', views.raw_records, name='raw_records'),
    path('sync/', views.sync_punches, name='sync_punches'),
    path('api/records/', views.api_ingest, name='api_ingest'),
    path('visitors/', views.visitors, name='visitors'),
    path('cards/', views.cards, name='cards'),
    path('sync-gateway/', views.gateway_sync, name='gateway_sync'),
    path('unis/', views.unis_bridge, name='unis_bridge'),
    path('unis/<int:bridge_id>/sync/', views.unis_sync, name='unis_sync'),
    path('device-users/', views.device_users, name='device_users'),
    path('gates/', views.gates, name='gates'),
]
