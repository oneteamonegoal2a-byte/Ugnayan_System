<?php
require_once '../includes/db.php';

function render_registration_status($type, $title, $message, $statusTitle, $statusText, $actionHref, $actionText)
{
    $isSuccess = $type === 'success';
    $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeStatusTitle = htmlspecialchars($statusTitle, ENT_QUOTES, 'UTF-8');
    $safeStatusText = htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8');
    $safeActionHref = htmlspecialchars($actionHref, ENT_QUOTES, 'UTF-8');
    $safeActionText = htmlspecialchars($actionText, ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>UGNAYAN - <?= $safeTitle ?></title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../css/register.css">
    </head>
    <body class="message-page">
        <div class="registration-container message-container">
            <header class="reg-header message-header">
                <a href="../login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> UGNAYAN</a>
            </header>

            <main class="form-card message-card">
                <section class="success-state <?= $isSuccess ? 'is-success' : 'is-error' ?>">
                    <div class="success-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                    <h2><?= $safeTitle ?></h2>
                    <p><?= $safeMessage ?></p>

                    <div class="status-box <?= $isSuccess ? 'pending-status' : 'error-status' ?>">
                        <strong><?= $safeStatusTitle ?></strong>
                        <span><?= $safeStatusText ?></span>
                    </div>

                    <a href="<?= $safeActionHref ?>" class="btn-primary">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                        <?= $safeActionText ?>
                    </a>
                </section>
            </main>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function upload_file($field)
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        render_registration_status(
            'error',
            'Invalid File Type',
            'One of your uploaded documents uses a file type that is not accepted.',
            'Accepted files',
            'Please upload JPG, JPEG, PNG, or PDF files only, then submit your registration again.',
            '../register.php',
            'Back to Registration'
        );
    }

    $name = uniqid($field.'_') . '.' . $ext;
    $path = '../assets/uploads/' . $name;

    move_uploaded_file($_FILES[$field]['tmp_name'], $path);

    return 'assets/uploads/' . $name;
}

$email = trim($_POST['email']);
$check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
$check->execute([$email]);

if ($check->fetch()) {
    render_registration_status(
        'error',
        'Email Already Registered',
        'Duplicate registration detected. This email address already exists in the system.',
        'Registration not submitted',
        'Use a different email address or return to login if this account already belongs to you.',
        '../register.php',
        'Back to Registration'
    );
}

$pdo->beginTransaction();

try {
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_hash'")->fetch();
    $passwordColumn = $columns ? 'password_hash' : 'password';

    $stmt = $pdo->prepare("INSERT INTO users (email, $passwordColumn, role, status) VALUES (?, ?, \"resident\", \"pending\")");
    $stmt->execute([$email, password_hash($_POST['password'], PASSWORD_DEFAULT)]);

    $userId = $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO residents (user_id, first_name, middle_name, last_name, suffix, birth_date, gender, civil_status, purok, contact_number, address, occupation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$userId, $_POST['first_name'], $_POST['middle_name'], $_POST['last_name'], $_POST['suffix'], $_POST['birth_date'], $_POST['gender'], $_POST['civil_status'], $_POST['purok'], $_POST['contact_number'], $_POST['address'], $_POST['occupation']]);

    $residentId = $pdo->lastInsertId();

    foreach ([['valid_id', 'Valid ID'], ['proof_residency', 'Proof of Residency'], ['photo', '2x2 Photo']] as $doc) {
        $file = upload_file($doc[0]);
        $stmt = $pdo->prepare('INSERT INTO resident_documents (resident_id, document_type, file_path) VALUES (?, ?, ?)');
        $stmt->execute([$residentId, $doc[1], $file]);
    }

    $stmt = $pdo->prepare('INSERT INTO activity_history (resident_id, activity_type, details) VALUES (?, "Registration", "Submitted resident registration for admin verification.")');
    $stmt->execute([$residentId]);

    log_action($pdo, $userId, 'Resident Registration', $email.' submitted a registration.');

    $pdo->commit();

    render_registration_status(
        'success',
        'Registration Submitted',
        'Please wait for admin approval. Your resident account will be reviewed before it can be used to sign in.',
        'Status: Pending Approval',
        'The barangay admin will verify your details and uploaded documents.',
        '../login.php',
        'Back to Login'
    );
} catch (Exception $e) {
    $pdo->rollBack();
    render_registration_status(
        'error',
        'Registration Failed',
        'We could not submit your registration at this time.',
        'Please try again',
        'If the issue continues, contact the barangay admin for assistance.',
        '../register.php',
        'Back to Registration'
    );
}
?>
