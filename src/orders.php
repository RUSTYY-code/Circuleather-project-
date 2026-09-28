<?php
    require_once "partials/session_check.php";

    if (!($_SESSION['mag_orders'] ?? false)) {
        header("Location: vooraad_beheer.php");
        exit();
    }

    $conn = require_once "partials/dbconnection.php";

    $bestellingenStmt = $conn->prepare("SELECT ID, locatie, email, status, besteldatum, verstuurdatum FROM bestellingen ORDER BY besteldatum DESC");
    $bestellingenStmt->execute();
    $bestellingen = $bestellingenStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $bestellingenStmt->close();

    // Let op: hier GEEN status != 'besteld'-filter op voorraad, zoals op vooraad_beheer.php.
    // De items van een bestelling moeten juist wel zichtbaar zijn in de bestelling zelf.
    $itemsStmt = $conn->prepare("
SELECT v.bestelling_ID AS bestellingen_ID, 1 AS aantal, v.prijs,
           v.leertype, v.kleur, v.dikteMM, v.lengteCM, v.breedteCM
        FROM voorraad v
     WHERE v.bestelling_ID != 0
    ORDER BY v.bestelling_ID
    ");
    $itemsStmt->execute();
    $alleItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
    $conn->close();

    // Items groeperen per bestelling, zodat we ze straks per bestelling kunnen tonen
    $itemsPerBestelling = [];
    foreach ($alleItems as $item) {
        $itemsPerBestelling[$item['bestellingen_ID']][] = $item; 
    }
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bestellingen - Circuleather</title>
    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body class="subpage">
    <div class="pagina-wrapper">
        <a class="terug-link" href="vooraad_beheer.php">&laquo; Terug naar voorraad</a>

        <div class="pagina-kaart invoer-kaart">
            <h1>Bestellingen</h1>
            <?php if (empty($bestellingen)): ?>
                <p class="geen-data">Er zijn nog geen bestellingen.</p>
            <?php endif; ?>
        </div>

        <?php foreach ($bestellingen as $bestelling): ?>
            <?php
                $items = $itemsPerBestelling[$bestelling['ID']] ?? [];
                $totaal = 0;
                foreach ($items as $item) {
                    $totaal += $item['aantal'] * $item['prijs'];
                }
            ?>
            <div class="pagina-kaart order-kaart">
                <div class="order-kop">
                    <h2>Bestelling #<?php echo (int) $bestelling['ID']; ?> &mdash; <?php echo htmlspecialchars($bestelling['locatie']); ?></h2>
                    <span class="order-status"><?php echo htmlspecialchars($bestelling['status']); ?></span>
                </div>
                <div class="order-meta">
                    <span>Email: <?php echo htmlspecialchars($bestelling['email']); ?></span>
                    <span>Besteld: <?php echo htmlspecialchars($bestelling['besteldatum']); ?></span>
                    <span>Verstuurd: <?php echo $bestelling['verstuurdatum'] ? htmlspecialchars($bestelling['verstuurdatum']) : '-'; ?></span>
                </div>

                <?php if (empty($items)): ?>
                    <p class="geen-data">Geen items in deze bestelling.</p>
                <?php else: ?>
                    <div class="tabel-scroll">
                        <table class="order-items">
                            <thead>
                                <tr>
                                    <th>Leertype</th>
                                    <th>Maat</th>
                                    <th>Dikte</th>
                                    <th>Kleur</th>
                                    <th>Aantal</th>
                                    <th>Prijs</th>
                                    <th>Subtotaal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['leertype']); ?></td>
                                        <td><?php echo htmlspecialchars($item['lengteCM']); ?> x <?php echo htmlspecialchars($item['breedteCM']); ?> cm</td>
                                        <td><?php echo htmlspecialchars($item['dikteMM']); ?> mm</td>
                                        <td><?php echo htmlspecialchars($item['kleur']); ?></td>
                                        <td><?php echo (int) $item['aantal']; ?></td>
                                        <td>&euro;<?php echo number_format((float) $item['prijs'], 2); ?></td>
                                        <td>&euro;<?php echo number_format($item['aantal'] * $item['prijs'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6">Totaal</td>
                                    <td>&euro;<?php echo number_format($totaal, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
