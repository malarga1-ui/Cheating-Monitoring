-- cleanup_teachers.sql
-- Remove students incorrectly inserted into teachers table.

-- Step 1: Remove teachers who appear in students table (same account + same moodle id)
DELETE t FROM teachers t
INNER JOIN students s ON s.moodle_user_id = t.moodle_teacher_id AND s.account_id = t.account_id;

-- Step 2: Remove teachers with no course assignments (orphans)
DELETE t FROM teachers t
LEFT JOIN course_teachers ct ON ct.moodle_teacher_id = t.moodle_teacher_id AND ct.account_id = t.account_id
WHERE ct.moodle_teacher_id IS NULL;
