from django.urls import path

from . import views

app_name = 'commerce'

urlpatterns = [
    # درخواست خرید (سازگار با نسخه پایه)
    path('', views.purchase_list, name='list'),
    path('new/', views.purchase_new, name='new'),
    path('dashboard/', views.dashboard, name='dashboard'),
    path('contracts/', views.contract_list, name='contract_list'),
    path('contracts/new/', views.contract_new, name='contract_new'),
    path('<int:pk>/', views.purchase_detail, name='detail'),
    path('<int:pk>/submit/', views.purchase_submit, name='submit'),
    path('<int:pk>/decide/', views.purchase_decide, name='decide'),
    path('<int:pk>/inquiry/', views.inquiry_add, name='inquiry_add'),
    path('<int:pk>/inquiry/<int:inquiry_pk>/select/', views.inquiry_select,
         name='inquiry_select'),

    # طرف‌های تجاری
    path('parties/', views.party_list, name='party_list'),
    path('parties/new/', views.party_new, name='party_new'),
    path('parties/<int:pk>/', views.party_detail, name='party_detail'),

    # سفارش خرید
    path('orders/', views.order_list, name='order_list'),
    path('orders/new/', views.order_new, name='order_new'),
    path('orders/<int:pk>/', views.order_detail, name='order_detail'),
    path('orders/<int:pk>/confirm/', views.order_confirm, name='order_confirm'),
    path('orders/<int:pk>/cancel/', views.order_cancel, name='order_cancel'),
    path('orders/<int:pk>/receive/', views.order_receive, name='order_receive'),
    path('orders/<int:pk>/print/', views.order_print, name='order_print'),

    # فاکتور خرید
    path('invoices/', views.invoice_list, name='invoice_list'),
    path('invoices/new/', views.invoice_new, name='invoice_new'),
    path('invoices/<int:pk>/', views.invoice_detail, name='invoice_detail'),
    path('invoices/<int:pk>/finalize/', views.invoice_finalize, name='invoice_finalize'),
    path('invoices/<int:pk>/cancel/', views.invoice_cancel, name='invoice_cancel'),
    path('invoices/<int:pk>/print/', views.invoice_print, name='invoice_print'),
    path('invoices/<int:pk>/installments/', views.invoice_installments,
         name='invoice_installments'),
    path('invoices/<int:pk>/pay/<int:inst_pk>/', views.installment_pay,
         name='installment_pay'),

    # فروش
    path('sales/', views.sales_list, name='sales_list'),
    path('sales/new/', views.sales_new, name='sales_new'),
    path('sales/<int:pk>/', views.sales_detail, name='sales_detail'),
    path('sales/<int:pk>/finalize/', views.sales_finalize, name='sales_finalize'),
    path('sales/<int:pk>/cancel/', views.sales_cancel, name='sales_cancel'),
    path('sales/<int:pk>/print/', views.sales_print, name='sales_print'),

    # خرید خارجی
    path('imports/', views.import_list, name='import_list'),
    path('imports/new/', views.import_new, name='import_new'),
    path('imports/<int:pk>/', views.import_detail, name='import_detail'),
    path('imports/<int:pk>/cost/', views.import_cost_add, name='import_cost_add'),
    path('imports/<int:pk>/status/', views.import_status, name='import_status'),

    # گزارش‌ها
    path('reports/commitments/', views.report_commitments, name='report_commitments'),
    path('reports/purchases/', views.report_purchases, name='report_purchases'),
    path('reports/sales/', views.report_sales, name='report_sales'),
    path('reports/purchases.xlsx', views.purchases_excel, name='purchases_excel'),
]
