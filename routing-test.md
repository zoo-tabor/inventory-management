# Routing Test Checklist

This document lists all routes that need to be tested to ensure proper navigation.

## Authentication Routes
- [ ] `index.php?route=login` - Login page
- [ ] `index.php?route=logout` - Logout and redirect
- [ ] `index.php?route=switch-company&id=1` - Switch to EKOSPOL
- [ ] `index.php?route=switch-company&id=2` - Switch to ZOO Tábor

## Dashboard
- [ ] `index.php?route=dashboard` - Main dashboard
- [ ] `index.php` (empty route) - Should show dashboard when logged in

## Stock Management
- [ ] `index.php?route=stock` - Stock overview
- [ ] `index.php?route=movements/prijem` - New receipt
- [ ] `index.php?route=movements/vydej` - New issue
- [ ] `index.php?route=movements` - Movement history

## Stocktaking
- [ ] `index.php?route=stocktaking` - Stocktaking list
- [ ] `index.php?route=stocktaking/start` - Start new stocktaking
- [ ] `index.php?route=stocktaking/count&id=X` - Perform counting (requires active stocktaking)

## Reports
- [ ] `index.php?route=reports/by-department` - Department reports
- [ ] `index.php?route=reports/by-employee` - Employee reports
- [ ] `index.php?route=reports/by-item` - Item reports

## Orders
- [ ] `index.php?route=orders` - Order proposals

## Admin - Master Data (requires admin login)
- [ ] `index.php?route=items` - Items list
- [ ] `index.php?route=categories` - Categories
- [ ] `index.php?route=locations` - Warehouses/Locations
- [ ] `index.php?route=departments` - Departments
- [ ] `index.php?route=employees` - Employees
- [ ] `index.php?route=users` - Users

## Navigation Links to Test

### Header Links
- [ ] Logo/App title → Dashboard
- [ ] Company switcher dropdown
- [ ] Settings link (admin only)
- [ ] Logout link

### Sidebar Navigation
- [ ] Dashboard
- [ ] Stock Overview
- [ ] New Issue
- [ ] New Receipt
- [ ] Movement History
- [ ] Stocktaking List
- [ ] New Stocktaking
- [ ] Reports → Department
- [ ] Reports → Employee
- [ ] Reports → Item
- [ ] Order Proposals
- [ ] Items (admin)
- [ ] Categories (admin)
- [ ] Locations (admin)
- [ ] Departments (admin)
- [ ] Employees (admin)
- [ ] Users (admin)

## Known Issues to Check
1. **Logout redirect** - Currently uses `/login` instead of `index.php?route=login`
2. **404 page dashboard link** - Uses `/dashboard` instead of `index.php?route=dashboard`
3. **CSS/JS asset paths** - Using `/assets/` which should work fine

## Testing Instructions

1. **Login**: Use `admin` / `admin123`
2. **Test each route** by clicking navigation links
3. **Check filters and forms** submit to correct routes
4. **Verify modals** and action buttons link properly
5. **Test breadcrumbs** and "back" links
6. **Test both companies** to ensure theme switching works

## Routes That Need Fixing

If any issues found, update:
- `includes/header.php` - Navigation links
- `index.php` - Logout redirect (line 78)
- `index.php` - 404 page link (line 170)
- Individual page files - Check all internal links
