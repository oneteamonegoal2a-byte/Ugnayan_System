<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Resident Registration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/register.css">
</head>
<body>
    <div class="registration-container">
        <header class="reg-header">
            <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> UGNAYAN</a>
            <h2>Resident Registration</h2>
        </header>

        <form class="form-card" action="actions/register_process.php" method="POST" enctype="multipart/form-data">
            <h3 class="step-title">Account Information</h3>

            <div class="input-group">
                <label>Email Address *</label>
                <input type="email" name="email" placeholder="yourname@email.com" required>
            </div>

            <div class="input-group">
                <label>Password *</label>
                <input type="password" name="password" placeholder="Min. 8 characters" required>
            </div>

            <h3 class="step-title">Personal Details</h3>

            <div class="form-row">
                <div class="input-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required>
                </div>

                <div class="input-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name">
                </div>

                <div class="input-group">
                    <label>Suffix</label>
                    <input type="text" name="suffix">
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <label>Date of Birth *</label>
                    <input type="date" name="birth_date" required>
                </div>

                <div class="input-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <label>Civil Status *</label>
                    <select name="civil_status" required>
                        <option>Single</option>
                        <option>Married</option>
                        <option>Widowed</option>
                        <option>Separated</option>
                    </select>
                </div>

                <div class="input-group">
                    <label>Purok *</label>
                    <select name="purok" required>
                        <option>Purok 1</option>
                        <option>Purok 2</option>
                        <option>Purok 3</option>
                        <option>Purok 4</option>
                    </select>
                </div>
            </div>

            <div class="input-group">
                <label>Contact Number *</label>
                <input type="tel" name="contact_number" placeholder="09XXXXXXXXX" required>
            </div>

            <div class="input-group">
                <label>Home Address *</label>
                <input type="text" name="address" required>
            </div>

            <div class="input-group">
                <label>Occupation</label>
                <input type="text" name="occupation">
            </div>

            <h3 class="step-title">Upload Required Documents</h3>

            <div class="input-group">
                <label>Valid ID *</label>
                <input type="file" name="valid_id" accept=".jpg,.jpeg,.png,.pdf" required>
            </div>

            <div class="input-group">
                <label>Proof of Residency *</label>
                <input type="file" name="proof_residency" accept=".jpg,.jpeg,.png,.pdf" required>
            </div>

            <div class="input-group">
                <label>2x2 Photo *</label>
                <input type="file" name="photo" accept=".jpg,.jpeg,.png" required>
            </div>

            <label class="checkbox-container">
                <input type="checkbox" required>
                <span class="terms-text">I certify that all information provided is true and correct.</span>
            </label>

            <div class="form-actions">
                <a href="login.php" class="btn-outline">Back to Login</a>
                <button type="submit" class="btn-primary">Submit Registration</button>
            </div>
        </form>
    </div>
</body>
</html>
