<?php
/**
 * Personal Finance Tracker - Single File PHP Application
 * Kakeibo-style finance tracking with SQLite
 */

// ================================
// CONFIGURATION & INITIALIZATION
// ================================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production

define('DB_PATH', __DIR__ . '/finance.db');
define('APP_NAME', 'Personal Finance Tracker');

// Color scheme for Kakeibo categories
$CATEGORY_COLORS = [
    'Needs' => '#3498db',       // Blue
    'Wants' => '#2ecc71',       // Green
    'Culture' => '#9b59b6',     // Purple
    'Unexpected' => '#e74c3c'   // Red
];

// ================================
// DATABASE FUNCTIONS
// ================================

/**
 * Initialize database connection
 */
function getDB() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
}

/**
 * Initialize database schema
 */
function initDatabase() {
    $db = getDB();

    // Create transactions table
    $db->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tanggal DATE NOT NULL,
            jumlah DECIMAL(15,2) NOT NULL,
            kategori VARCHAR(50) NOT NULL,
            subkategori VARCHAR(100) NOT NULL,
            deskripsi TEXT,
            metode_pembayaran VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create categories table for subcategories
    $db->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_name VARCHAR(50) NOT NULL,
            subcategory_name VARCHAR(100) NOT NULL,
            is_default BOOLEAN DEFAULT 1,
            UNIQUE(category_name, subcategory_name)
        )
    ");

    // Insert default subcategories if not exists
    $count = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count == 0) {
        $defaultSubcategories = [
            // Needs
            ['Needs', 'Housing', 1],
            ['Needs', 'Food & Groceries', 1],
            ['Needs', 'Transportation', 1],
            ['Needs', 'Healthcare', 1],
            ['Needs', 'Insurance', 1],
            // Wants
            ['Wants', 'Entertainment', 1],
            ['Wants', 'Dining Out', 1],
            ['Wants', 'Shopping', 1],
            ['Wants', 'Travel', 1],
            // Culture
            ['Culture', 'Education', 1],
            ['Culture', 'Books & Media', 1],
            ['Culture', 'Hobbies', 1],
            // Unexpected
            ['Unexpected', 'Emergency', 1],
            ['Unexpected', 'Car Repair', 1],
            ['Unexpected', 'Medical Emergency', 1]
        ];

        $stmt = $db->prepare("INSERT INTO categories (category_name, subcategory_name, is_default) VALUES (?, ?, ?)");
        foreach ($defaultSubcategories as $cat) {
            $stmt->execute($cat);
        }
    }

    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tanggal ON transactions(tanggal)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kategori ON transactions(kategori)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_subkategori ON transactions(subkategori)");

    // Set database permissions
    chmod(DB_PATH, 0660);
}

/**
 * Get all subcategories for a category
 */
function getSubcategories($category) {
    $db = getDB();
    $stmt = $db->prepare("SELECT DISTINCT subcategory_name FROM categories WHERE category_name = ? ORDER BY subcategory_name");
    $stmt->execute([$category]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Add a new transaction
 */
function addTransaction($data) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO transactions (tanggal, jumlah, kategori, subkategori, deskripsi, metode_pembayaran)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([
        $data['tanggal'],
        $data['jumlah'],
        $data['kategori'],
        $data['subkategori'],
        $data['deskripsi'],
        $data['metode_pembayaran']
    ]);
}

/**
 * Update a transaction
 */
function updateTransaction($id, $data) {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE transactions
        SET tanggal = ?, jumlah = ?, kategori = ?, subkategori = ?, deskripsi = ?, metode_pembayaran = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    return $stmt->execute([
        $data['tanggal'],
        $data['jumlah'],
        $data['kategori'],
        $data['subkategori'],
        $data['deskripsi'],
        $data['metode_pembayaran'],
        $id
    ]);
}

/**
 * Delete a transaction
 */
function deleteTransaction($id) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM transactions WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * Get a single transaction
 */
function getTransaction($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Get all transactions with optional filters
 */
function getTransactions($filters = []) {
    $db = getDB();
    $sql = "SELECT * FROM transactions WHERE 1=1";
    $params = [];

    if (!empty($filters['start_date'])) {
        $sql .= " AND tanggal >= :start_date";
        $params[':start_date'] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $sql .= " AND tanggal <= :end_date";
        $params[':end_date'] = $filters['end_date'];
    }

    if (!empty($filters['kategori'])) {
        $sql .= " AND kategori = :kategori";
        $params[':kategori'] = $filters['kategori'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND deskripsi LIKE :search";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    $sql .= " ORDER BY tanggal DESC, id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get monthly summary data
 */
function getMonthlySummary($year, $month) {
    $db = getDB();
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));

    // Get totals by category
    $stmt = $db->prepare("
        SELECT kategori,
               SUM(CASE WHEN jumlah > 0 THEN jumlah ELSE 0 END) as total_income,
               SUM(CASE WHEN jumlah < 0 THEN ABS(jumlah) ELSE 0 END) as total_expense
        FROM transactions
        WHERE tanggal BETWEEN ? AND ?
        GROUP BY kategori
    ");
    $stmt->execute([$startDate, $endDate]);
    $byCategory = $stmt->fetchAll();

    // Get overall totals
    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN jumlah > 0 THEN jumlah ELSE 0 END) as total_income,
            SUM(CASE WHEN jumlah < 0 THEN ABS(jumlah) ELSE 0 END) as total_expense,
            SUM(jumlah) as net_balance
        FROM transactions
        WHERE tanggal BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $totals = $stmt->fetch();

    return [
        'by_category' => $byCategory,
        'totals' => $totals
    ];
}

/**
 * Get monthly trend data
 */
function getMonthlyTrends($months = 6) {
    $db = getDB();
    $data = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $date = date('Y-m', strtotime("-$i months"));
        $year = date('Y', strtotime("-$i months"));
        $month = date('m', strtotime("-$i months"));

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN jumlah > 0 THEN jumlah ELSE 0 END) as income,
                SUM(CASE WHEN jumlah < 0 THEN ABS(jumlah) ELSE 0 END) as expense
            FROM transactions
            WHERE tanggal BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $result = $stmt->fetch();

        $data[] = [
            'month' => date('M Y', strtotime("-$i months")),
            'income' => (float)($result['income'] ?? 0),
            'expense' => (float)($result['expense'] ?? 0),
            'balance' => (float)($result['income'] ?? 0) - (float)($result['expense'] ?? 0)
        ];
    }

    return $data;
}

// ================================
// SECURITY & VALIDATION
// ================================

/**
 * Generate CSRF token
 */
function getCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate and sanitize input
 */
function validateInput($data) {
    if (is_array($data)) {
        return array_map('validateInput', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate transaction data
 */
function validateTransaction($data) {
    $errors = [];

    if (empty($data['tanggal'])) {
        $errors[] = 'Tanggal is required';
    }

    if (empty($data['jumlah']) || !is_numeric($data['jumlah'])) {
        $errors[] = 'Valid jumlah is required';
    }

    if (empty($data['kategori']) || !in_array($data['kategori'], ['Needs', 'Wants', 'Culture', 'Unexpected'])) {
        $errors[] = 'Valid kategori is required';
    }

    if (empty($data['subkategori'])) {
        $errors[] = 'Subkategori is required';
    }

    if (empty($data['deskripsi'])) {
        $errors[] = 'Deskripsi is required';
    }

    return $errors;
}

// ================================
// SVG CHART GENERATORS
// ================================

/**
 * Generate SVG Pie Chart
 */
function generatePieChart($data, $width = 400, $height = 400) {
    if (empty($data)) {
        return '<svg width="' . $width . '" height="' . $height . '"><text x="50%" y="50%" text-anchor="middle" fill="#999">No data</text></svg>';
    }

    $total = array_sum(array_column($data, 'value'));
    if ($total <= 0) {
        return '<svg width="' . $width . '" height="' . $height . '"><text x="50%" y="50%" text-anchor="middle" fill="#999">No data</text></svg>';
    }

    $cx = $width / 2;
    $cy = $height / 2;
    $radius = min($width, $height) / 2 - 40;

    $colors = ['#3498db', '#2ecc71', '#9b59b6', '#e74c3c', '#f39c12', '#1abc9c'];
    $colorIndex = 0;

    $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';

    $currentAngle = -90;
    $sliceIndex = 0;

    foreach ($data as $item) {
        $percentage = ($item['value'] / $total) * 100;
        if ($percentage <= 0) continue;

        $angle = ($percentage / 100) * 360;
        $endAngle = $currentAngle + $angle;

        $startX = $cx + $radius * cos(deg2rad($currentAngle));
        $startY = $cy + $radius * sin(deg2rad($currentAngle));
        $endX = $cx + $radius * cos(deg2rad($endAngle));
        $endY = $cy + $radius * sin(deg2rad($endAngle));

        $largeArc = $angle > 180 ? 1 : 0;

        $path = "M {$cx} {$cy} L {$startX} {$startY} A {$radius} {$radius} 0 {$largeArc} 1 {$endX} {$endY} Z";

        $color = $item['color'] ?? $colors[$colorIndex % count($colors)];
        $colorIndex++;

        $svg .= '<path d="' . $path . '" fill="' . $color . '" stroke="white" stroke-width="2"/>';

        // Label
        $labelAngle = $currentAngle + ($angle / 2);
        $labelRadius = $radius * 0.7;
        $labelX = $cx + $labelRadius * cos(deg2rad($labelAngle));
        $labelY = $cy + $labelRadius * sin(deg2rad($labelAngle));

        $svg .= '<text x="' . $labelX . '" y="' . $labelY . '" text-anchor="middle" fill="white" font-size="12" font-weight="bold">';
        $svg .= round($percentage) . '%</text>';

        // Legend
        $legendY = 20 + ($sliceIndex * 25);
        $svg .= '<rect x="10" y="' . $legendY . '" width="15" height="15" fill="' . $color . '"/>';
        $svg .= '<text x="30" y="' . ($legendY + 12) . '" font-size="12" fill="#333">' . escapeHtml($item['label']) . ' (' . number_format($item['value'], 0, ',', '.') . ')</text>';

        $currentAngle = $endAngle;
        $sliceIndex++;
    }

    $svg .= '</svg>';
    return $svg;
}

/**
 * Generate SVG Line Chart
 */
function generateLineChart($data, $width = 600, $height = 300) {
    if (empty($data)) {
        return '<svg width="' . $width . '" height="' . $height . '"><text x="50%" y="50%" text-anchor="middle" fill="#999">No data</text></svg>';
    }

    $padding = 40;
    $chartWidth = $width - ($padding * 2);
    $chartHeight = $height - ($padding * 2);

    $values = array_column($data, 'value');
    $max = max($values);
    $min = min($values);
    $range = $max - $min;
    if ($range == 0) $range = 1;

    $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';

    // Draw grid lines
    for ($i = 0; $i <= 5; $i++) {
        $y = $padding + ($chartHeight / 5) * $i;
        $svg .= '<line x1="' . $padding . '" y1="' . $y . '" x2="' . ($width - $padding) . '" y2="' . $y . '" stroke="#e0e0e0" stroke-width="1"/>';
        $value = $max - (($range / 5) * $i);
        $svg .= '<text x="5" y="' . ($y + 5) . '" font-size="10" fill="#999">'
      . number_format($value, 0, ',', '.')
      . '</text>';
    }

    // Generate path
    $path = '';
    foreach ($data as $index => $item) {
        $x = $padding + ($index / (count($data) - 1)) * $chartWidth;
        $y = $height - $padding - (($item['value'] - $min) / $range) * $chartHeight;

        $path .= ($index === 0 ? 'M' : ' L') . ' ' . $x . ' ' . $y;
    }

    $svg .= '<path d="' . $path . '" fill="none" stroke="#3498db" stroke-width="2"/>';

    // Add data points
    foreach ($data as $index => $item) {
        $x = $padding + ($index / (count($data) - 1)) * $chartWidth;
        $y = $height - $padding - (($item['value'] - $min) / $range) * $chartHeight;

        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="4" fill="#3498db"/>';

        // X-axis labels
        if (count($data) <= 12 || $index % 2 == 0) {
            $svg .= '<text x="' . $x . '" y="' . ($height - 10) . '" text-anchor="middle" font-size="10" fill="#666">' . escapeHtml($item['label']) . '</text>';
        }
    }

    $svg .= '</svg>';
    return $svg;
}

// ================================
// EXPORT FUNCTIONS
// ================================

/**
 * Export to CSV
 */
function exportToCSV($data) {
    $filename = 'finance_export_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header
    fputcsv($output, ['Tanggal', 'Jumlah', 'Kategori', 'Subkategori', 'Deskripsi', 'Metode Pembayaran'], ';');

    // Data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['tanggal'],
            $row['jumlah'],
            $row['kategori'],
            $row['subkategori'],
            $row['deskripsi'],
            $row['metode_pembayaran']
        ], ';');
    }

    fclose($output);
    exit;
}

// ================================
// MAIN CONTROLLER
// ================================

// Initialize database
initDatabase();

// Handle actions
$action = $_GET['action'] ?? 'dashboard';
$message = '';
$error = '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        if ($action === 'add') {
            $input = validateInput($_POST);
            $errors = validateTransaction($input);

            if (empty($errors)) {
                // Convert jumlah to negative for expenses (expense is positive input from user)
                if (isset($input['is_expense']) && $input['is_expense'] === '1') {
                    $input['jumlah'] = -abs($input['jumlah']);
                } else {
                    $input['jumlah'] = abs($input['jumlah']);
                }

                if (addTransaction($input)) {
                    $message = 'Transaction added successfully!';
                    $action = 'dashboard';
                } else {
                    $error = 'Failed to add transaction.';
                }
            } else {
                $error = implode('<br>', $errors);
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'] ?? 0;
            $input = validateInput($_POST);
            $errors = validateTransaction($input);

            if (empty($errors)) {
                if (isset($input['is_expense']) && $input['is_expense'] === '1') {
                    $input['jumlah'] = -abs($input['jumlah']);
                } else {
                    $input['jumlah'] = abs($input['jumlah']);
                }

                if (updateTransaction($id, $input)) {
                    $message = 'Transaction updated successfully!';
                    $action = 'dashboard';
                } else {
                    $error = 'Failed to update transaction.';
                }
            } else {
                $error = implode('<br>', $errors);
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? 0;
            if (deleteTransaction($id)) {
                $message = 'Transaction deleted successfully!';
                $action = 'dashboard';
            } else {
                $error = 'Failed to delete transaction.';
            }
        } elseif ($action === 'export_csv') {
            $filters = [
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'kategori' => $_POST['kategori'] ?? ''
            ];
            $data = getTransactions($filters);
            exportToCSV($data);
        }
    }
}

// Handle GET actions
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'export_csv') {
        $filters = [
            'start_date' => $_GET['start_date'] ?? '',
            'end_date' => $_GET['end_date'] ?? '',
            'kategori' => $_GET['kategori'] ?? ''
        ];
        $data = getTransactions($filters);
        exportToCSV($data);
    }
}

// ================================
// VIEW FUNCTIONS
// ================================

function escapeHtml($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount) {
    return 'Rp ' . number_format(abs($amount), 0, ',', '.');
}

/**
 * Dashboard View
 */
function showDashboard() {
    global $CATEGORY_COLORS;

    // Get filters
    $filters = [];
    if (!empty($_GET['start_date'])) $filters['start_date'] = $_GET['start_date'];
    if (!empty($_GET['end_date'])) $filters['end_date'] = $_GET['end_date'];
    if (!empty($_GET['kategori'])) $filters['kategori'] = $_GET['kategori'];
    if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

    $transactions = getTransactions($filters);
    $currentMonth = date('Y-m');
    $summary = getMonthlySummary(date('Y'), date('m'));
    $trends = getMonthlyTrends(6);

    // Prepare pie chart data
    $pieData = [];
    foreach ($summary['by_category'] as $cat) {
        if ($cat['total_expense'] > 0) {
            $pieData[] = [
                'label' => $cat['kategori'],
                'value' => $cat['total_expense'],
                'color' => $CATEGORY_COLORS[$cat['kategori']] ?? '#999'
            ];
        }
    }

    // Prepare line chart data (expenses trend)
    $lineData = [];
    foreach ($trends as $trend) {
        $lineData[] = [
            'label' => $trend['month'],
            'value' => $trend['expense']
        ];
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo APP_NAME; ?></title>
        <style>
            /* Mobile-First CSS Approach - Base styles for mobile */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #f5f7fa;
                color: #333;
                line-height: 1.6;
                /* Better mobile font rendering */
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                /* Mobile-first padding */
                padding: 15px;
            }

            header {
                background: white;
                padding: 20px 15px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin-bottom: 15px;
            }

            h1 {
                color: #2c3e50;
                margin-bottom: 5px;
                /* Responsive font size */
                font-size: 1.5rem;
            }

            .subtitle {
                color: #7f8c8d;
                font-size: 0.875rem;
            }

            /* Grid system - Mobile first: single column */
            .grid {
                display: grid;
                gap: 15px;
            }

            /* Cards - Mobile: single column, Tablet+: multi-column */
            .cards {
                grid-template-columns: 1fr;
            }

            .card {
                background: white;
                padding: 16px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .card h3 {
                font-size: 0.75rem;
                color: #7f8c8d;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .card .amount {
                font-size: 1.5rem;
                font-weight: bold;
            }

            .card.income .amount {
                color: #2ecc71;
            }

            .card.expense .amount {
                color: #e74c3c;
            }

            .card.balance .amount {
                color: #3498db;
            }

            /* Transaction form */
            .transaction-form {
                background: white;
                padding: 16px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin-bottom: 15px;
            }

            .transaction-form h3 {
                margin-bottom: 15px;
                color: #2c3e50;
                font-size: 1.125rem;
            }

            /* Form grid - Mobile: single column */
            .form-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
                margin-bottom: 15px;
            }

            .form-group {
                display: flex;
                flex-direction: column;
            }

            .form-group label {
                font-size: 0.75rem;
                font-weight: 600;
                color: #555;
                margin-bottom: 5px;
            }

            /* Mobile-first: larger touch targets */
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px; /* Prevents zoom on iOS */
                min-height: 44px; /* Better touch target */
            }

            .form-group textarea {
                resize: vertical;
                min-height: 80px;
            }

            .transaction-type {
                display: flex;
                gap: 15px;
                margin-bottom: 15px;
            }

            .transaction-type label {
                display: flex;
                align-items: center;
                gap: 5px;
                cursor: pointer;
                font-size: 0.875rem;
            }

            /* Buttons - Mobile optimized */
            .btn {
                padding: 12px 20px;
                border: none;
                border-radius: 4px;
                font-size: 0.875rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                min-height: 44px; /* Touch target */
                width: 100%; /* Mobile: full width */
                text-align: center;
            }

            .btn-primary {
                background: #3498db;
                color: white;
            }

            .btn-primary:hover {
                background: #2980b9;
            }

            .btn-primary:active {
                background: #1c5a85;
            }

            .btn-secondary {
                background: #95a5a6;
                color: white;
            }

            .btn-secondary:hover {
                background: #7f8c8d;
            }

            .btn-secondary:active {
                background: #6c7b7d;
            }

            .btn-danger {
                background: #e74c3c;
                color: white;
            }

            .btn-danger:hover {
                background: #c0392b;
            }

            .btn-danger:active {
                background: #922b21;
            }

            /* Charts - Mobile: single column */
            .charts {
                grid-template-columns: 1fr;
            }

            .chart-container {
                background: white;
                padding: 16px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .chart-container h3 {
                margin-bottom: 12px;
                color: #2c3e50;
                font-size: 1rem;
            }

            .chart-container svg {
                width: 100%;
                height: auto;
            }

            /* Filters - Mobile first */
            .filters {
                background: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin-bottom: 15px;
            }

            .filters form {
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
                align-items: flex-end;
            }

            .filters .form-group {
                flex: 1;
            }

            /* Table - Mobile */
            .transactions-table {
                background: white;
                padding: 16px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                overflow-x: auto;
                /* Hide table headers on mobile - show as cards instead */
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th {
                background: #f8f9fa;
                padding: 10px 8px;
                text-align: left;
                font-weight: 600;
                color: #2c3e50;
                border-bottom: 2px solid #e0e0e0;
                font-size: 0.75rem;
            }

            td {
                padding: 12px 8px;
                border-bottom: 1px solid #f0f0f0;
                font-size: 0.875rem;
            }

            tr:hover {
                background: #f8f9fa;
            }

            .category-badge {
                display: inline-block;
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 600;
                color: white;
            }

            .message {
                background: #d4edda;
                color: #155724;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 15px;
                font-size: 0.875rem;
            }

            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 15px;
                font-size: 0.875rem;
            }

            .actions {
                display: flex;
                gap: 5px;
                flex-wrap: wrap;
            }

            .btn-small {
                padding: 8px 12px;
                font-size: 0.75rem;
                min-height: 32px;
                width: auto;
            }

            /* Tablet breakpoint (min-width: 768px) */
            @media (min-width: 768px) {
                .container {
                    padding: 20px;
                }

                header {
                    padding: 20px;
                    margin-bottom: 20px;
                }

                h1 {
                    font-size: 1.75rem;
                }

                .subtitle {
                    font-size: 0.875rem;
                }

                .grid {
                    gap: 20px;
                }

                /* Cards: 2-3 columns on tablet */
                .cards {
                    grid-template-columns: repeat(2, 1fr);
                }

                .card {
                    padding: 20px;
                }

                .card h3 {
                    font-size: 0.875rem;
                    margin-bottom: 10px;
                }

                .card .amount {
                    font-size: 1.75rem;
                }

                /* Form: 2 columns on tablet */
                .transaction-form {
                    padding: 20px;
                    margin-bottom: 20px;
                }

                .form-grid {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                    margin-bottom: 15px;
                }

                .form-group label {
                    font-size: 0.875rem;
                }

                .form-group input,
                .form-group select {
                    font-size: 14px;
                    min-height: 38px;
                }

                .transaction-type label {
                    font-size: 1rem;
                }

                .btn {
                    width: auto;
                    padding: 10px 20px;
                    font-size: 0.875rem;
                    min-height: 38px;
                }

                /* Charts: 2 columns on tablet */
                .charts {
                    grid-template-columns: repeat(2, 1fr);
                }

                .chart-container {
                    padding: 20px;
                }

                .chart-container h3 {
                    font-size: 1.125rem;
                    margin-bottom: 15px;
                }

                .filters {
                    padding: 15px;
                    margin-bottom: 20px;
                }

                .filters form {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                }

                .filters .form-group {
                    flex: 1;
                    min-width: 150px;
                }

                .filters .btn {
                    height: 38px;
                    width: auto;
                }

                .transactions-table {
                    padding: 20px;
                }

                th {
                    padding: 12px;
                    font-size: 0.875rem;
                }

                td {
                    padding: 12px;
                    font-size: 0.875rem;
                }

                .category-badge {
                    font-size: 0.875rem;
                    padding: 4px 8px;
                }

                .message,
                .error {
                    padding: 15px;
                    font-size: 0.875rem;
                }
            }

            /* Desktop breakpoint (min-width: 1024px) */
            @media (min-width: 1024px) {
                .container {
                    padding: 30px;
                }

                /* Cards: 3 columns on desktop */
                .cards {
                    grid-template-columns: repeat(3, 1fr);
                }

                .card .amount {
                    font-size: 2rem;
                }

                /* Form: 3-4 columns on desktop */
                .form-grid {
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                }

                /* Charts stay 2 columns on desktop */
                .charts {
                    grid-template-columns: repeat(2, 1fr);
                }

                .chart-container svg {
                    max-width: 100%;
                }
            }

            /* Large desktop breakpoint (min-width: 1200px) */
            @media (min-width: 1200px) {
                .charts {
                    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <h1><?php echo APP_NAME; ?></h1>
                <p class="subtitle">Kakeibo-inspired Personal Finance Tracker</p>
            </header>

            <?php if ($message): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="grid cards">
                <div class="card income">
                    <h3>Total Income</h3>
                    <div class="amount"><?php echo formatCurrency($summary['totals']['total_income'] ?? 0); ?></div>
                </div>
                <div class="card expense">
                    <h3>Total Expense</h3>
                    <div class="amount"><?php echo formatCurrency($summary['totals']['total_expense'] ?? 0); ?></div>
                </div>
                <div class="card balance">
                    <h3>Net Balance</h3>
                    <div class="amount"><?php echo formatCurrency($summary['totals']['net_balance'] ?? 0); ?></div>
                </div>
            </div>

            <br />

            <!-- Transaction Form -->
            <div class="transaction-form">
                <h3 style="margin-bottom: 15px;">Add Transaction</h3>
                <form method="POST" action="?action=add">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

                    <div class="transaction-type">
                        <label>
                            <input type="radio" name="transaction_type" value="expense" checked onchange="toggleTransactionType(this.value)">
                            Expense
                        </label>
                        <label>
                            <input type="radio" name="transaction_type" value="income" onchange="toggleTransactionType(this.value)">
                            Income
                        </label>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="tanggal" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="jumlah" required placeholder="0">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="kategori" required onchange="updateSubcategories(this.value)">
                                <option value="">Select Category</option>
                                <option value="Needs">Needs</option>
                                <option value="Wants">Wants</option>
                                <option value="Culture">Culture</option>
                                <option value="Unexpected">Unexpected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subcategory</label>
                            <select name="subkategori" required>
                                <option value="">Select Subcategory</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <input type="text" name="metode_pembayaran" placeholder="Cash, Card, Transfer...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="deskripsi" required placeholder="Transaction description..."></textarea>
                    </div>

                    <input type="hidden" name="is_expense" id="is_expense" value="1">
                    <br />
                    <button type="submit" class="btn btn-primary">Add Transaction</button>
                </form>
            </div>

            <!-- Charts -->
            <div class="grid charts">
                <div class="chart-container">
                    <h3>Expense Distribution by Category</h3>
                    <?php echo generatePieChart($pieData, 400, 400); ?>
                </div>
                <div class="chart-container">
                    <h3>Monthly Expense Trend (Last 6 Months)</h3>
                    <?php echo generateLineChart($lineData, 400, 300); ?>
                </div>
            </div>

            <br />

            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?php echo $_GET['start_date'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?php echo $_GET['end_date'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="kategori">
                            <option value="">All Categories</option>
                            <option value="Needs" <?php echo ($_GET['kategori'] ?? '') === 'Needs' ? 'selected' : ''; ?>>Needs</option>
                            <option value="Wants" <?php echo ($_GET['kategori'] ?? '') === 'Wants' ? 'selected' : ''; ?>>Wants</option>
                            <option value="Culture" <?php echo ($_GET['kategori'] ?? '') === 'Culture' ? 'selected' : ''; ?>>Culture</option>
                            <option value="Unexpected" <?php echo ($_GET['kategori'] ?? '') === 'Unexpected' ? 'selected' : ''; ?>>Unexpected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search Description</label>
                        <input type="text" name="search" value="<?php echo escapeHtml($_GET['search'] ?? ''); ?>" placeholder="Search...">
                    </div>
                    <button type="submit" class="btn btn-secondary">Filter</button>
                    <a href="?" class="btn btn-secondary">Reset</a>
                    <a href="?action=export_csv<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>" class="btn btn-primary">Export CSV</a>
                </form>
            </div>

            <!-- Transactions Table -->
            <div class="transactions-table">
                <h3 style="margin-bottom: 15px;">Transactions</h3>
                <?php if (empty($transactions)): ?>
                    <p style="text-align: center; color: #999; padding: 20px;">No transactions found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Category</th>
                                <th>Subcategory</th>
                                <th>Description</th>
                                <th>Payment Method</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?php echo escapeHtml($t['tanggal']); ?></td>
                                    <td style="color: <?php echo $t['jumlah'] < 0 ? '#e74c3c' : '#2ecc71'; ?>; font-weight: 600;">
                                        <?php echo formatCurrency($t['jumlah']); ?>
                                    </td>
                                    <td>
                                        <span class="category-badge" style="background: <?php echo $CATEGORY_COLORS[$t['kategori']] ?? '#999'; ?>">
                                            <?php echo escapeHtml($t['kategori']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo escapeHtml($t['subkategori']); ?></td>
                                    <td><?php echo escapeHtml($t['deskripsi']); ?></td>
                                    <td><?php echo escapeHtml($t['metode_pembayaran']); ?></td>
                                    <td class="actions">
                                        <a href="?action=edit&id=<?php echo $t['id']; ?>" class="btn btn-small btn-secondary">Edit</a>
                                        <form method="POST" action="?action=delete" style="display: inline;" onsubmit="return confirm('Are you sure?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
            function updateSubcategories(category) {
                const subcategorySelect = document.querySelector('select[name="subkategori"]');
                subcategorySelect.innerHTML = '<option value="">Loading...</option>';

                // Predefined subcategories
                const subcategories = {
                    'Needs': ['Housing', 'Food & Groceries', 'Transportation', 'Healthcare', 'Insurance'],
                    'Wants': ['Entertainment', 'Dining Out', 'Shopping', 'Travel'],
                    'Culture': ['Education', 'Books & Media', 'Hobbies'],
                    'Unexpected': ['Emergency', 'Car Repair', 'Medical Emergency']
                };

                subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
                if (subcategories[category]) {
                    subcategories[category].forEach(sub => {
                        const option = document.createElement('option');
                        option.value = sub;
                        option.textContent = sub;
                        subcategorySelect.appendChild(option);
                    });
                }
            }

            function toggleTransactionType(type) {
                document.getElementById('is_expense').value = type === 'expense' ? '1' : '0';
            }

            // Initialize subcategories on page load
            window.addEventListener('DOMContentLoaded', () => {
                const categorySelect = document.querySelector('select[name="kategori"]');
                if (categorySelect.value) {
                    updateSubcategories(categorySelect.value);
                }
            });
        </script>
    </body>
    </html>
    <?php
}

/**
 * Edit Transaction View
 */
function showEditForm($id) {
    global $CATEGORY_COLORS;

    $transaction = getTransaction($id);
    if (!$transaction) {
        header('Location: ?');
        exit;
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edit Transaction - <?php echo APP_NAME; ?></title>
        <style>
            /* Mobile-First CSS for Edit Form */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #f5f7fa;
                color: #333;
                line-height: 1.6;
                /* Mobile-first: tighter padding on mobile */
                padding: 15px;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
            }

            .card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            h1 {
                color: #2c3e50;
                margin-bottom: 20px;
                font-size: 1.5rem;
            }

            /* Form grid - Mobile: single column */
            .form-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
                margin-bottom: 15px;
            }

            .form-group {
                display: flex;
                flex-direction: column;
            }

            .form-group label {
                font-size: 0.75rem;
                font-weight: 600;
                color: #555;
                margin-bottom: 5px;
            }

            /* Mobile: larger touch targets, prevents iOS zoom */
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                min-height: 44px;
            }

            .form-group textarea {
                grid-column: 1 / -1;
                resize: vertical;
                min-height: 80px;
            }

            .transaction-type {
                display: flex;
                gap: 15px;
                margin-bottom: 15px;
                flex-wrap: wrap;
            }

            .transaction-type label {
                display: flex;
                align-items: center;
                gap: 5px;
                cursor: pointer;
                font-size: 0.875rem;
            }

            /* Buttons - Mobile: full width */
            .btn {
                padding: 12px 20px;
                border: none;
                border-radius: 4px;
                font-size: 0.875rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                min-height: 44px;
                width: 100%;
                text-align: center;
            }

            .btn-primary {
                background: #3498db;
                color: white;
            }

            .btn-primary:hover {
                background: #2980b9;
            }

            .btn-primary:active {
                background: #1c5a85;
            }

            .btn-secondary {
                background: #95a5a6;
                color: white;
                text-decoration: none;
                display: inline-block;
            }

            .btn-secondary:hover {
                background: #7f8c8d;
            }

            .btn-secondary:active {
                background: #6c7b7d;
            }

            .actions {
                display: flex;
                gap: 10px;
                margin-top: 20px;
                flex-direction: column;
            }

            /* Tablet breakpoint (min-width: 768px) */
            @media (min-width: 768px) {
                body {
                    padding: 20px;
                }

                .card {
                    padding: 30px;
                }

                h1 {
                    font-size: 1.75rem;
                    margin-bottom: 20px;
                }

                /* Form grid - Tablet: 2 columns */
                .form-grid {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                    margin-bottom: 15px;
                }

                .form-group label {
                    font-size: 0.875rem;
                }

                .form-group input,
                .form-group select {
                    font-size: 14px;
                    min-height: 38px;
                }

                .transaction-type label {
                    font-size: 1rem;
                }

                .btn {
                    width: auto;
                    padding: 10px 20px;
                    font-size: 0.875rem;
                    min-height: 38px;
                }

                .actions {
                    flex-direction: row;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <h1>Edit Transaction</h1>
                <form method="POST" action="?action=edit">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">

                    <div class="transaction-type">
                        <label>
                            <input type="radio" name="transaction_type" value="expense" <?php echo $transaction['jumlah'] < 0 ? 'checked' : ''; ?> onchange="toggleTransactionType(this.value)">
                            Expense
                        </label>
                        <label>
                            <input type="radio" name="transaction_type" value="income" <?php echo $transaction['jumlah'] > 0 ? 'checked' : ''; ?> onchange="toggleTransactionType(this.value)">
                            Income
                        </label>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="tanggal" required value="<?php echo $transaction['tanggal']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="jumlah" required value="<?php echo abs($transaction['jumlah']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="kategori" required onchange="updateSubcategories(this.value)">
                                <option value="">Select Category</option>
                                <option value="Needs" <?php echo $transaction['kategori'] === 'Needs' ? 'selected' : ''; ?>>Needs</option>
                                <option value="Wants" <?php echo $transaction['kategori'] === 'Wants' ? 'selected' : ''; ?>>Wants</option>
                                <option value="Culture" <?php echo $transaction['kategori'] === 'Culture' ? 'selected' : ''; ?>>Culture</option>
                                <option value="Unexpected" <?php echo $transaction['kategori'] === 'Unexpected' ? 'selected' : ''; ?>>Unexpected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subcategory</label>
                            <select name="subkategori" required>
                                <option value="">Select Subcategory</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <input type="text" name="metode_pembayaran" value="<?php echo escapeHtml($transaction['metode_pembayaran']); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="deskripsi" required><?php echo escapeHtml($transaction['deskripsi']); ?></textarea>
                    </div>

                    <input type="hidden" name="is_expense" id="is_expense" value="<?php echo $transaction['jumlah'] < 0 ? '1' : '0'; ?>">

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Update Transaction</button>
                        <a href="?" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function updateSubcategories(category) {
                const subcategorySelect = document.querySelector('select[name="subkategori"]');
                subcategorySelect.innerHTML = '<option value="">Loading...</option>';

                const subcategories = {
                    'Needs': ['Housing', 'Food & Groceries', 'Transportation', 'Healthcare', 'Insurance'],
                    'Wants': ['Entertainment', 'Dining Out', 'Shopping', 'Travel'],
                    'Culture': ['Education', 'Books & Media', 'Hobbies'],
                    'Unexpected': ['Emergency', 'Car Repair', 'Medical Emergency']
                };

                subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
                if (subcategories[category]) {
                    subcategories[category].forEach(sub => {
                        const option = document.createElement('option');
                        option.value = sub;
                        option.textContent = sub;
                        subcategorySelect.appendChild(option);
                    });
                }

                // Try to select the current subcategory
                const currentSubcategory = '<?php echo escapeHtml($transaction['subkategori']); ?>';
                subcategorySelect.value = currentSubcategory;
            }

            function toggleTransactionType(type) {
                document.getElementById('is_expense').value = type === 'expense' ? '1' : '0';
            }

            // Initialize on page load
            window.addEventListener('DOMContentLoaded', () => {
                const categorySelect = document.querySelector('select[name="kategori"]');
                if (categorySelect.value) {
                    updateSubcategories(categorySelect.value);
                }
            });
        </script>
    </body>
    </html>
    <?php
}

// ================================
// ROUTER
// ================================

if ($action === 'edit' && isset($_GET['id'])) {
    showEditForm($_GET['id']);
} else {
    showDashboard();
}
?>
