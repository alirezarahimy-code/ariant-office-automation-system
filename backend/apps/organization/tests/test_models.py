"""آزمون مدل‌های سازمانی: درخت واحدها، حذف نرم و ممیزی تغییرات."""
from django.test import TestCase

from apps.organization.models import Department, Employee


class DepartmentTreeTests(TestCase):
    def test_tree_parent_child(self):
        hq = Department.objects.create(title='دبیرخانه مرکزی', code='100')
        child = Department.objects.create(
            title='واحد نامه‌ها', code='101', parent=hq)
        self.assertEqual(child.parent_id, hq.id)
        self.assertEqual(list(hq.get_children()), [child])
        self.assertEqual(child.level, 1)

    def test_code_unique(self):
        Department.objects.create(title='واحد اول', code='200')
        with self.assertRaises(Exception):
            Department.objects.create(title='واحد تکراری', code='200')

    def test_str_contains_title_and_code(self):
        d = Department(title='فناوری اطلاعات', code='120')
        self.assertIn('فناوری اطلاعات', str(d))
        self.assertIn('120', str(d))


class SoftDeleteTests(TestCase):
    def test_delete_is_soft(self):
        d = Department.objects.create(title='واحد موقت', code='300')
        d.delete()
        d.refresh_from_db()
        self.assertTrue(d.is_deleted)
        self.assertEqual(Department.objects.filter(code='300').count(), 0)
        self.assertEqual(
            Department.all_objects.filter(code='300').count(), 1)

    def test_employee_delete_is_soft(self):
        e = Employee.objects.create(
            first_name='رضا', last_name='شریفی', personnel_code='9001')
        e.delete()
        self.assertEqual(Employee.objects.filter(personnel_code='9001').count(), 0)
        self.assertTrue(
            Employee.all_objects.get(personnel_code='9001').is_deleted)


class AuditHistoryTests(TestCase):
    def test_changes_are_recorded(self):
        """هر ایجاد/ویرایش باید در تاریخچه ممیزی ثبت شود."""
        d = Department.objects.create(title='عنوان اول', code='400')
        self.assertEqual(d.history.count(), 1)

        d.title = 'عنوان دوم'
        d.save()
        self.assertEqual(d.history.count(), 2)

        oldest = d.history.order_by('history_date').first()
        self.assertEqual(oldest.title, 'عنوان اول')
        newest = d.history.order_by('-history_date').first()
        self.assertEqual(newest.title, 'عنوان دوم')

    def test_employee_history_records_department_change(self):
        d1 = Department.objects.create(title='واحد یک', code='501')
        d2 = Department.objects.create(title='واحد دو', code='502')
        e = Employee.objects.create(
            first_name='سارا', last_name='احمدی', personnel_code='9002',
            department=d1)
        e.department = d2
        e.save()
        old = e.history.order_by('history_date').first()
        self.assertEqual(old.department_id, d1.id)


class EmployeeModelTests(TestCase):
    def test_full_name_and_str(self):
        e = Employee.objects.create(
            first_name='مریم', last_name='رضایی', personnel_code='1001')
        self.assertEqual(e.full_name, 'مریم رضایی')
        self.assertIn('1001', str(e))

    def test_employee_belongs_to_department(self):
        d = Department.objects.create(title='دبیرخانه', code='600')
        e = Employee.objects.create(
            first_name='علی', last_name='محمدی', personnel_code='1002',
            department=d)
        self.assertEqual(e.department, d)
        self.assertEqual(list(d.employees.all()), [e])
