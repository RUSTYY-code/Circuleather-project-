<?php
    session_start();

    if (isset($_SESSION['ingelogd'])) {
        header("Location: vooraad_beheer.php");
        exit();
    }

    $conn = require_once "partials/dbconnection.php";
    $foutmelding = '';

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'inloggen') {
        $gebruiker = trim($_POST['username'] ?? '');
        $wachtwoord = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT id, password, mag_insert, mag_orders FROM gebruikers WHERE username = ?");
        $stmt->bind_param("s", $gebruiker);
        $stmt->execute();
        $rij = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rij) {
            $foutmelding = 'Gebruikersnaam niet gevonden.';
        } elseif (!password_verify($wachtwoord, $rij['password'])) {
            $foutmelding = 'Onjuist wachtwoord.';
        } else {
            $_SESSION['ingelogd'] = true;
            $_SESSION['username'] = $gebruiker;
            $_SESSION['mag_insert'] = (bool) $rij['mag_insert'];
            $_SESSION['mag_orders'] = (bool) $rij['mag_orders'];
            $_SESSION['last_activity'] = time();
            header("Location: vooraad_beheer.php");
            exit();
        }
    }
    $conn->close();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen - Circuleather</title>
    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body class="subpage subpage-gecentreerd">
    <div class="pagina-kaart auth-kaart">
        <h1>Inloggen bij Circuleather</h1>

        <?php if ($foutmelding !== ''): ?>
            <div class="melding melding-fout"><?php echo htmlspecialchars($foutmelding); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="melding melding-fout">Je sessie is verlopen, log opnieuw in.</div>
        <?php endif; ?>

        <?php if (isset($_GET['geregistreerd'])): ?>
            <div class="melding melding-succes">Account aangemaakt, je kan nu inloggen.</div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="inloggen">
            <input class="form-input" type="text" name="username" placeholder="Gebruikersnaam" required>
            <input class="form-input" type="password" name="password" placeholder="Wachtwoord" required>
            <button class="btn-primary" type="submit">Inloggen</button>
        </form>

        <p>Nog geen account? <a href="registreer.php">Registreer hier</a></p>
    </div>
</body>
</html>
