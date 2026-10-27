<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Global Utility Functions & Helpers
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Sanitize user input for safe HTML display.
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize form input strings.
 */
function cleanInput(?string $data): string {
    if ($data === null) {
        return '';
    }
    return trim(strip_tags($data));
}

/**
 * Format currency in Eswatini Lilangeni (E / SZL).
 */
function formatCurrency($amount): string {
    $num = is_numeric($amount) ? (float)$amount : 0.0;
    return 'E ' . number_format($num, 2);
}

/**
 * Format date/datetime safely.
 */
function formatDate(?string $dateString, string $format = 'd M Y, H:i'): string {
    if (empty($dateString) || $dateString === '0000-00-00 00:00:00' || $dateString === '0000-00-00') {
        return '—';
    }
    try {
        $date = new DateTime($dateString);
        return $date->format($format);
    } catch (Exception $e) {
        return e($dateString);
    }
}

/**
 * Set a flash notification message in session.
 */
function setFlash(string $type, string $message): void {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'text' => $message
    ];
}

/**
 * Retrieve and clear flash notification messages.
 */
function getFlashMessages(): array {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Render flash alert banners.
 */
function renderFlashMessages(): void {
    $messages = getFlashMessages();
    foreach ($messages as $msg) {
        $alertClass = match ($msg['type']) {
            'success' => 'alert-success',
            'danger', 'error' => 'alert-danger',
            'warning' => 'alert-warning',
            default => 'alert-info'
        };
        $iconClass = match ($msg['type']) {
            'success' => 'bi-check-circle-fill',
            'danger', 'error' => 'bi-exclamation-triangle-fill',
            'warning' => 'bi-exclamation-circle-fill',
            default => 'bi-info-circle-fill'
        };
        echo '<div class="alert ' . $alertClass . ' alert-dismissible fade show d-flex align-items-center mb-4 shadow-sm" role="alert">';
        echo '<i class="bi ' . $iconClass . ' me-2 fs-5"></i>';
        echo '<div>' . e($msg['text']) . '</div>';
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

/**
 * Generate or get existing CSRF token.
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token.
 */
function validateCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get HTML badge for status.
 */
function getStatusBadge(string $status): string {
    $class = match (strtolower($status)) {
        'active', 'achieved', 'completed', 'resolved' => 'bg-success',
        'in progress' => 'bg-primary',
        'open', 'planning' => 'bg-info text-dark',
        'pending', 'on hold' => 'bg-warning text-dark',
        'delayed', 'critical', 'cancelled', 'inactive', 'suspended' => 'bg-danger',
        'closed' => 'bg-secondary',
        default => 'bg-dark'
    };
    return '<span class="badge ' . $class . ' px-2 py-1">' . e($status) . '</span>';
}

/**
 * Get HTML badge for ticket priority.
 */
function getPriorityBadge(string $priority): string {
    $class = match (strtolower($priority)) {
        'critical' => 'bg-danger text-white',
        'high' => 'bg-warning text-dark',
        'medium' => 'bg-info text-dark',
        'low' => 'bg-secondary text-white',
        default => 'bg-light text-dark'
    };
    return '<span class="badge ' . $class . ' px-2 py-1">' . e($priority) . '</span>';
}

/**
 * Safe database error interpreter for MySQL trigger signals.
 * Extracts clean user-friendly text if triggered by SIGNAL SQLSTATE '45000'.
 */
function parseDatabaseErrorMessage(Throwable $e): string {
    $msg = $e->getMessage();
    
    // Check if error comes from a MySQL custom SIGNAL SQLSTATE '45000'
    if (preg_match('/SQLSTATE\[45000\]:.*?:\s*\d+\s*(.*?)(?:$|\n)/i', $msg, $matches)) {
        return trim($matches[1]);
    }

    // Check for foreign key constraint violation
    if (str_contains($msg, '1451') || str_contains($msg, 'a foreign key constraint fails')) {
        return 'Cannot delete or modify this record because related items exist in other modules. Please remove or reassign linked records first.';
    }

    // Check for duplicate entry
    if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry')) {
        return 'A record with these unique details (such as username, SKU, or email) already exists in the system.';
    }

    // Default safe message
    return 'Database validation rule rejected the operation. Please review input constraints.';
}
