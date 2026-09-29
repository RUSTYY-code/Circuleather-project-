<?php
    // Start een sessie om geregistreerde gebruikers direct door te kunnen sturen.
    session_start();

    // Ingelogde gebruikers hoeven geen nieuw account aan te maken.
    if (isset($_SESSION['ingelogd'])) {
        header("Location: vooraad_beheer.php");
        exit();
    }

    $conn = require_once "partials/dbconnection.php";
    $foutmelding = '';

    // Verwerk het registratieformulier wanneer dit met POST wordt verzonden.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'registreer') {
        $gebruiker = trim($_POST['username'] ?? '');
        $wachtwoord = $_POST['password'] ?? '';

        if ($gebruiker === '' || $wachtwoord === '') {
            $foutmelding = 'Vul een gebruikersnaam en wachtwoord in.';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM gebruikers WHERE username = ?");
            $checkStmt->bind_param("s", $gebruiker);
            $checkStmt->execute();
            $bestaatAl = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            // Voorkom dubbele gebruikersnamen voordat het account wordt opgeslagen.
            if ($bestaatAl) {
                $foutmelding = 'Deze gebruikersnaam bestaat al.';
            } else {
                $hash = password_hash($wachtwoord, PASSWORD_DEFAULT);
                // mag_insert en mag_orders staan hier bewust niet bij: nieuwe accounts
                // krijgen standaard geen van beide (kolommen hebben DEFAULT 0), dat zet
                // je voorlopig met de hand in de database totdat er een beheerscherm is.
                $stmt = $conn->prepare("INSERT INTO gebruikers (username, password) VALUES (?, ?)");
                $stmt->bind_param("ss", $gebruiker, $hash);
                if ($stmt->execute()) {
                    $stmt->close();
                    $conn->close();
                    header("Location: login.php?geregistreerd=1");
                    exit();
                } else {
                    $foutmelding = 'Registratie mislukt: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
    $conn->close();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registreren - Circuleather</title>
    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body class="subpage subpage-gecentreerd">
    <div class="pagina-kaart auth-kaart">
        <h1>Account aanmaken</h1>

        <?php if ($foutmelding !== ''): ?>
            <div class="melding melding-fout"><?php echo htmlspecialchars($foutmelding); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="registreer">
            <input class="form-input" type="text" name="username" placeholder="Gebruikersnaam" required>
            <input class="form-input" type="password" name="password" placeholder="Wachtwoord" required>
            <button class="btn-primary" type="submit">Account aanmaken</button>
        </form>

        <p>Heb je al een account? <a href="login.php">Log hier in</a></p>
    </div>
</body>
</html>
