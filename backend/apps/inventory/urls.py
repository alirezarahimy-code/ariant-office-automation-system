from django.urls import path

from . import views

app_name = 'inventory'

urlpatterns = [
    # کالا و کارت کالا
    path('', views.item_list, name='list'),
    path('dashboard/', views.dashboard, name='dashboard'),
    path('items/new/', views.item_new, name='item_new'),
    path('groups/', views.group_list, name='groups'),
    path('new/', views.move_new, name='move_new'),
    path('balance/', views.balance, name='balance'),
    path('value/', views.value_report, name='value'),
    path('transfer/', views.transfer_new, name='transfer'),
    path('count/apply/', views.count_apply, name='count_apply'),
    path('move/<int:pk>/print/', views.move_print, name='move_print'),

    # اسناد چندردیفی
    path('docs/', views.doc_list, name='doc_list'),
    path('docs/new/', views.doc_new, name='doc_new'),
    path('docs/<int:pk>/', views.doc_detail, name='doc_detail'),
    path('docs/<int:pk>/confirm/', views.doc_confirm, name='doc_confirm'),
    path('docs/<int:pk>/cancel/', views.doc_cancel, name='doc_cancel'),
    path('docs/<int:pk>/return/', views.doc_return, name='doc_return'),
    path('docs/<int:pk>/print/', views.doc_print, name='doc_print'),

    # انبارگردانی
    path('count/', views.count_list, name='count_list'),
    path('count/new/', views.count_new, name='count_new'),
    path('count/<int:pk>/', views.count_detail, name='count_detail'),
    path('count/<int:pk>/line/', views.count_line_save, name='count_line_save'),
    path('count/<int:pk>/import/', views.count_import, name='count_import'),
    path('count/<int:pk>/tags/', views.count_tags, name='count_tags'),
    path('count/<int:pk>/tags/print/', views.count_tags_print, name='count_tags_print'),
    path('count/<int:pk>/apply/', views.count_apply_view, name='count_apply_view'),

    # قیمت‌گذاری
    path('prices/', views.price_lists, name='price_lists'),
    path('prices/<int:pk>/', views.price_list_detail, name='price_list_detail'),

    # گزارش‌ها و ابزار
    path('reports/no-movement/', views.report_no_movement, name='no_movement'),
    path('reports/expiry/', views.report_expiry, name='expiry'),
    path('reports/serials/', views.report_serials, name='serials'),
    path('reports/balance.xlsx', views.balance_excel, name='balance_excel'),
    path('reports/movement/<int:pk>.xlsx', views.movement_excel, name='movement_excel'),
    path('reports/accounting.xlsx', views.accounting_excel, name='accounting_excel'),
    path('reports/count-template.xlsx', views.count_template_excel, name='count_template'),
    path('labels/', views.barcode_labels, name='barcode_labels'),
    path('warehouses/', views.warehouse_list, name='warehouses'),
    path('periods/', views.period_list, name='periods'),

    # کارت کالا (پایین‌تر از مسیرهای خاص تا تداخل نکند)
    path('<int:pk>/', views.item_detail, name='detail'),
    path('<int:pk>/movement/', views.movement, name='movement'),
    path('<int:pk>/edit/', views.item_edit, name='item_edit'),
]
