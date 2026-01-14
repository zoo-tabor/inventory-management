<?php
/**
 * Migration: Fix code columns for existing records
 * Generate unique codes for existing records that have empty codes
 */

return function($db) {
    // Fix locations - generate unique codes for existing records
    $stmt = $db->query("SELECT id, name FROM locations WHERE code = '' OR code IS NULL");
    $locations = $stmt->fetchAll();

    foreach ($locations as $location) {
        // Generate code from name (first 4 chars uppercase + id)
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $location['name']), 0, 4)) . $location['id'];
        $updateStmt = $db->prepare("UPDATE locations SET code = ? WHERE id = ?");
        $updateStmt->execute([$code, $location['id']]);
    }

    // Fix departments - generate unique codes for existing records
    $stmt = $db->query("SELECT id, name FROM departments WHERE code = '' OR code IS NULL");
    $departments = $stmt->fetchAll();

    foreach ($departments as $dept) {
        // Generate code from name (first 4 chars uppercase + id)
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $dept['name']), 0, 4)) . $dept['id'];
        $updateStmt = $db->prepare("UPDATE departments SET code = ? WHERE id = ?");
        $updateStmt->execute([$code, $dept['id']]);
    }

    // Fix employees - generate unique employee numbers for existing records without one
    $stmt = $db->query("SELECT id FROM employees WHERE employee_number = '' OR employee_number IS NULL");
    $employees = $stmt->fetchAll();

    foreach ($employees as $emp) {
        // Generate employee number: EMP + padded id (e.g., EMP001, EMP002)
        $empNumber = 'EMP' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT);
        $updateStmt = $db->prepare("UPDATE employees SET employee_number = ? WHERE id = ?");
        $updateStmt->execute([$empNumber, $emp['id']]);
    }
};
