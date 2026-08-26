<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Set CSRF cookie early
csrf_cookie();

if (empty($_SESSION['user_id'])) {
    redirect('auth/login.php');
}

$user_id = (int) $_SESSION['user_id'];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error = 'All fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } else {
            // Verify current/temporary password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $password_valid = false;
            
            if ($user_row && password_verify($current_password, $user_row['password'])) {
                $password_valid = true;
            }

            if ($password_valid) {
                // Hash new password
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update users table
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $new_hash, $user_id);
                if ($stmt->execute()) {
                    // Clear temporary password flags
                    $stmt_temp = $conn->prepare("SELECT id, message_meta FROM messages WHERE sender_id = ? AND JSON_EXTRACT(message_meta, '$.must_change_password') = true");
                    $stmt_temp->bind_param('i', $user_id);
                    $stmt_temp->execute();
                    $result = $stmt_temp->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $meta = json_decode($row['message_meta'], true);
                        if ($meta && !empty($meta['must_change_password'])) {
                            unset($meta['must_change_password']);
                            unset($meta['expires_at']);
                            $new_meta = json_encode($meta);
                            $update_stmt = $conn->prepare("UPDATE messages SET message_meta = ? WHERE id = ?");
                            $update_stmt->bind_param('si', $new_meta, $row['id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                    }
                    $stmt_temp->close();

                    // Remove must_change_password flag
                    unset($_SESSION['must_change_password']);
                    set_flash('success', 'Your password has been changed successfully.');
                    
                    // Redirect to dashboard
                    $role = $_SESSION['role'] ?? '';
                    if ($role === 'company' || $role === 'freelancer') {
                        redirect('index.php');
                    } else {
                        redirect('admin/admin_dashboard.php');
                    }
                } else {
                    $error = 'An error occurred while saving the new password.';
                }
                $stmt->close();
            } else {
                $error = 'Invalid current/temporary password.';
            }
        }
    }
}

$page_title = 'Change Password';
// Use a clean layout since they shouldn't see navbar links until they change password
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e('FreelanceHub') ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            }
        }
    };
    </script>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
      .auth-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 50%, #ede9fe 100%);
      }
      .dark .auth-wrapper {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
      }
      .auth-card {
        width: 100%;
        max-width: 440px;
        background: var(--color-card);
        border: 1px solid var(--color-border);
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.06);
        padding: 2rem;
      }
      .auth-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--color-input-border);
        border-radius: 8px;
        background: var(--color-input-bg);
        color: var(--color-text-primary);
        outline: none;
      }
      .auth-input:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
      }
      .auth-submit {
        width: 100%;
        padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
      }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200">
<div class="auth-wrapper">
  <div class="auth-card">
    <div class="text-center mb-6">
      <h2 class="text-2xl font-bold gradient-text">Change Password</h2>
      <?php if (!empty($_SESSION['must_change_password'])): ?>
          <p class="mt-2 text-sm text-yellow-600 dark:text-yellow-400">
              You are using a temporary password. Please create a new password before continuing.
          </p>
      <?php else: ?>
          <p class="mt-2 text-sm text-gray-500">Update your account password</p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-100 text-red-700 border border-red-200 text-sm">
        <?= $error ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div>
        <label class="block text-sm font-medium mb-1">Current / Temporary Password</label>
        <input type="password" name="current_password" required class="auth-input">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">New Password</label>
        <input type="password" name="new_password" required class="auth-input">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Confirm New Password</label>
        <input type="password" name="confirm_password" required class="auth-input">
      </div>

      <button type="submit" class="auth-submit mt-2">
        Change Password
      </button>
    </form>
  </div>
</div>
</body>
</html>
