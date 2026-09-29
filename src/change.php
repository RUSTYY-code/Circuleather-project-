<?php
require_once "partials/session_check.php";

if (!($_SESSION['mag_insert'] ?? false)) {
	header("Location: vooraad_beheer.php");
	exit();
}

$conn = require_once "partials/dbconnection.php";
$fout = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wijzig_stuk') {
	$voorraadId = (int) ($_POST['voorraad_id'] ?? 0);
	$leertype = trim($_POST['leertype'] ?? '');
	$dikteMM = (int) ($_POST['dikteMM'] ?? -1);
	$lengteCM = (int) ($_POST['lengteCM'] ?? -1);
	$breedteCM = (int) ($_POST['breedteCM'] ?? -1);
	$kleur = trim($_POST['kleur'] ?? '');
	$prijs = (float) ($_POST['prijs'] ?? -1);
	$gewichtG = (float) ($_POST['gewichtG'] ?? -1);
	$bruikbaarheid = (float) ($_POST['bruikbaarheid'] ?? -1);
	$herkomst = trim($_POST['herkomst'] ?? '');
	$datum = trim($_POST['datum'] ?? '');

	$filters = array_filter([
		'leertype' => trim($_POST['filter_leertype'] ?? ''),
		'kleur' => trim($_POST['filter_kleur'] ?? ''),
		'dikte' => trim($_POST['filter_dikte'] ?? ''),
		'maat' => trim($_POST['filter_maat'] ?? ''),
	], static fn($value) => $value !== '');

	$controleStmt = $conn->prepare("SELECT v.status, v.bestelling_ID, oi.ontvangst_id FROM voorraad v LEFT JOIN OntvangstItem oi ON oi.voorraad_id = v.id WHERE v.id = ? LIMIT 1");
	$controleStmt->bind_param("i", $voorraadId);
	$controleStmt->execute();
	$stuk = $controleStmt->get_result()->fetch_assoc();
	$controleStmt->close();

	if (!$stuk || $stuk['status'] === 'besteld' || (int) ($stuk['bestelling_ID'] ?? 0) !== 0) {
		$filters['fout'] = 'besteld';
		header("Location: change.php?" . http_build_query($filters));
		exit();
	}

	$ontvangstId = (int) ($stuk['ontvangst_id'] ?? 0);
	if ($voorraadId < 1 || $leertype === '' || $dikteMM < 0 || $lengteCM < 0 || $breedteCM < 0 || $prijs < 0 || $gewichtG < 0 || $bruikbaarheid < 0 || ($ontvangstId > 0 && ($herkomst === '' || $datum === ''))) {
		$filters['fout'] = 'gegevens';
		header("Location: change.php?" . http_build_query($filters));
		exit();
	}

	try {
		$conn->begin_transaction();

		$updateVoorraadStmt = $conn->prepare("UPDATE voorraad SET leertype = ?, dikteMM = ?, lengteCM = ?, breedteCM = ?, gewichtG = ?, kleur = ?, prijs = ? WHERE id = ? AND status != 'besteld' AND bestelling_ID = 0");
		$updateVoorraadStmt->bind_param("siiidsdi", $leertype, $dikteMM, $lengteCM, $breedteCM, $gewichtG, $kleur, $prijs, $voorraadId);
		$updateVoorraadStmt->execute();
		if ($updateVoorraadStmt->affected_rows < 0) {
			throw new RuntimeException('Voorraadstuk kon niet worden bijgewerkt.');
		}
		$updateVoorraadStmt->close();

		if ($ontvangstId > 0) {
			$updateItemStmt = $conn->prepare("UPDATE OntvangstItem SET gewichtG = ?, bruikbaarheid = ? WHERE ontvangst_id = ? AND voorraad_id = ?");
			$updateItemStmt->bind_param("ddii", $gewichtG, $bruikbaarheid, $ontvangstId, $voorraadId);
			$updateItemStmt->execute();
			$updateItemStmt->close();

			$updateOntvangstStmt = $conn->prepare("UPDATE Ontvangst SET herkomst = ?, datum = ? WHERE ID = ?");
			$updateOntvangstStmt->bind_param("ssi", $herkomst, $datum, $ontvangstId);
			$updateOntvangstStmt->execute();
			$updateOntvangstStmt->close();
		}

		$conn->commit();
		$filters['gewijzigd'] = '1';
	} catch (Throwable $e) {
		$conn->rollback();
		$filters['fout'] = 'opslaan';
	}

	header("Location: change.php?" . http_build_query($filters));
	exit();
}

$geselecteerdLeertype = trim($_GET['leertype'] ?? '');
$geselecteerdKleur = trim($_GET['kleur'] ?? '');
$geselecteerdDikte = trim($_GET['dikte'] ?? '');
$geselecteerdMaat = trim($_GET['maat'] ?? '');
$maatCategorieen = ['A' => [23, 40], 'B' => [40, 60], 'C' => [60, null]];
$maatCaseSql = "CASE WHEN (v.lengteCM >= 23 AND v.lengteCM < 40) OR (v.breedteCM >= 23 AND v.breedteCM < 40) THEN 'A' WHEN (v.lengteCM >= 40 AND v.lengteCM < 60) OR (v.breedteCM >= 40 AND v.breedteCM < 60) THEN 'B' WHEN v.lengteCM >= 60 OR v.breedteCM >= 60 THEN 'C' END";

$leertypeStmt = $conn->query("SELECT DISTINCT leertype FROM voorraad WHERE status != 'besteld' ORDER BY leertype");
$leertypes = $leertypeStmt->fetch_all(MYSQLI_ASSOC);
$kleurStmt = $conn->query("SELECT DISTINCT kleur FROM voorraad WHERE status != 'besteld' ORDER BY kleur");
$kleuren = $kleurStmt->fetch_all(MYSQLI_ASSOC);
$dikteStmt = $conn->query("SELECT DISTINCT dikteMM FROM voorraad WHERE status != 'besteld' ORDER BY dikteMM");
$diktes = $dikteStmt->fetch_all(MYSQLI_ASSOC);

$condities = ["v.status != 'besteld'"];
$params = [];
$types = '';
if ($geselecteerdLeertype !== '') {
	$condities[] = 'v.leertype = ?';
	$params[] = $geselecteerdLeertype;
	$types .= 's';
}
if ($geselecteerdKleur !== '') {
	$condities[] = 'v.kleur = ?';
	$params[] = $geselecteerdKleur;
	$types .= 's';
}
if ($geselecteerdDikte !== '' && ctype_digit($geselecteerdDikte)) {
	$condities[] = 'v.dikteMM = ?';
	$params[] = (int) $geselecteerdDikte;
	$types .= 'i';
}
    if ($geselecteerdMaat !== '' && isset($maatCategorieen[$geselecteerdMaat])) {
	$condities[] = "$maatCaseSql = ?";
	$params[] = $geselecteerdMaat;
	$types .= 's';
}
$whereSql = ' WHERE ' . implode(' AND ', $condities);

$itemsPerPagina = 12;
$huidigePagina = max(1, (int) ($_GET['pagina'] ?? 1));
$totaalStmt = $conn->prepare("SELECT COUNT(*) AS totaal FROM voorraad v" . $whereSql);
if ($params) {
	$totaalStmt->bind_param($types, ...$params);
}
$totaalStmt->execute();
$totaalItems = (int) $totaalStmt->get_result()->fetch_assoc()['totaal'];
$totaalStmt->close();
$totaalPaginas = max(1, (int) ceil($totaalItems / $itemsPerPagina));
$huidigePagina = min($huidigePagina, $totaalPaginas);
$offset = ($huidigePagina - 1) * $itemsPerPagina;

$lijstParams = $params;
$lijstParams[] = $itemsPerPagina;
$lijstParams[] = $offset;
$lijstStmt = $conn->prepare("SELECT v.id, v.leertype, v.dikteMM, v.lengteCM, v.breedteCM, v.gewichtG, v.kleur, v.prijs, v.status, oi.ontvangst_id, oi.bruikbaarheid, o.herkomst, o.datum FROM voorraad v LEFT JOIN OntvangstItem oi ON oi.voorraad_id = v.id LEFT JOIN Ontvangst o ON o.ID = oi.ontvangst_id" . $whereSql . " ORDER BY v.id DESC LIMIT ? OFFSET ?");
$lijstStmt->bind_param($types . 'ii', ...$lijstParams);
$lijstStmt->execute();
$producten = $lijstStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lijstStmt->close();
$conn->close();

$filterQuery = array_filter([
	'leertype' => $geselecteerdLeertype,
	'kleur' => $geselecteerdKleur,
	'dikte' => $geselecteerdDikte,
	'maat' => $geselecteerdMaat,
], static fn($value) => $value !== '');
$vanafPagina = max(1, $huidigePagina - 2);
$totPagina = min($totaalPaginas, $vanafPagina + 4);
$vanafPagina = max(1, $totPagina - 4);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Voorraad wijzigen - Circuleather</title>
	<link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body>
	<header class="header">
		<div class="titel"><img src="png/titel.png" alt="Circuleather"></div>
		<div class="inNout-wrapper">
			<nav class="inNout">
				<div class="inNoutB"><a href="vooraad_beheer.php"><button type="button">Voorraad</button></a></div>
				<div class="inNoutB"><a href="insert.php"><button type="button">Ontvangst</button></a></div>
				<?php if ($_SESSION['mag_orders'] ?? false): ?>
					<div class="inNoutB"><a href="orders.php"><button type="button">Bestellingen</button></a></div>
				<?php endif; ?>
			</nav>
			<div class="inNout-user">
				<span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
				<a href="logout.php">Uitloggen</a>
			</div>
		</div>
	</header>

	<?php if (isset($_GET['gewijzigd'])): ?>
		<div class="melding-banner">Voorraadstuk bijgewerkt.</div>
	<?php elseif (isset($_GET['fout'])): ?>
		<div class="melding melding-fout change-message"><?php
			$meldingen = [
				'besteld' => 'Dit stuk is besteld of bestaat niet meer en kan niet worden gewijzigd.',
				'gegevens' => 'Controleer de ingevulde gegevens.',
				'opslaan' => 'De wijziging kon niet worden opgeslagen.',
			];
			echo htmlspecialchars($meldingen[$_GET['fout']] ?? 'Er is iets misgegaan.');
		?></div>
	<?php endif; ?>

	<div class="main-container">
		<aside class="sidebar">
			<form class="filter-opties" method="get">
				<p>Filter opties</p>
				<div class="filter-selects">
					<select class="filter-select" name="leertype" onchange="this.form.submit()">
						<option value="">Alle leertypes</option>
						<?php foreach ($leertypes as $rij): ?>
							<option value="<?php echo htmlspecialchars($rij['leertype']); ?>"<?php echo $rij['leertype'] === $geselecteerdLeertype ? ' selected' : ''; ?>><?php echo htmlspecialchars($rij['leertype']); ?></option>
						<?php endforeach; ?>
					</select>
					<select class="filter-select" name="kleur" onchange="this.form.submit()">
						<option value="">Alle kleuren</option>
						<?php foreach ($kleuren as $rij): ?>
							<option value="<?php echo htmlspecialchars($rij['kleur']); ?>"<?php echo $rij['kleur'] === $geselecteerdKleur ? ' selected' : ''; ?>><?php echo htmlspecialchars($rij['kleur']); ?></option>
						<?php endforeach; ?>
					</select>
					<select class="filter-select" name="dikte" onchange="this.form.submit()">
						<option value="">Alle diktes</option>
						<?php foreach ($diktes as $rij): ?>
							<option value="<?php echo (int) $rij['dikteMM']; ?>"<?php echo (string) $rij['dikteMM'] === $geselecteerdDikte ? ' selected' : ''; ?>><?php echo (int) $rij['dikteMM']; ?> mm</option>
						<?php endforeach; ?>
					</select>
					<select class="filter-select" name="maat" onchange="this.form.submit()">
						<option value="">Alle maten</option>
						<option value="A"<?php echo $geselecteerdMaat === 'A' ? ' selected' : ''; ?>>23-40 cm</option>
						<option value="B"<?php echo $geselecteerdMaat === 'B' ? ' selected' : ''; ?>>40-60 cm</option>
						<option value="C"<?php echo $geselecteerdMaat === 'C' ? ' selected' : ''; ?>>60+ cm</option>
					</select>
				</div>
				<a class="reset-filters" href="change.php">Reset filters</a>
			</form>
			<nav class="pagination" aria-label="Paginering">
				<div class="page-numbers">
					<?php for ($pagina = $vanafPagina; $pagina <= $totPagina; $pagina++): ?>
						<a class="page-btn<?php echo $pagina === $huidigePagina ? ' active' : ''; ?>" href="?<?php echo http_build_query(array_merge($filterQuery, ['pagina' => $pagina])); ?>"><?php echo $pagina; ?></a>
					<?php endfor; ?>
				</div>
				<div class="page-nav">
					<a class="page-btn<?php echo $huidigePagina <= 1 ? ' disabled' : ''; ?>" href="?<?php echo http_build_query(array_merge($filterQuery, ['pagina' => max(1, $huidigePagina - 1)])); ?>">&laquo;</a>
					<a class="page-btn<?php echo $huidigePagina >= $totaalPaginas ? ' disabled' : ''; ?>" href="?<?php echo http_build_query(array_merge($filterQuery, ['pagina' => min($totaalPaginas, $huidigePagina + 1)])); ?>">&raquo;</a>
				</div>
			</nav>
		</aside>

		<main class="content">
			<div class="change-heading">
				<div>
					<h1>Voorraad wijzigen</h1>
					<p><?php echo $totaalItems; ?> beschikbare voorraadstukken</p>
				</div>
				<a class="terug-link" href="vooraad_beheer.php">Terug naar voorraad</a>
			</div>
			<?php if (empty($producten)): ?>
				<p class="geen-data">Geen voorraadstukken gevonden met deze filters.</p>
			<?php else: ?>
				<div class="product-grid">
					<?php foreach ($producten as $product): ?>
						<article class="product-card" id="change-<?php echo (int) $product['id']; ?>">
							<div class="naam-box"><span>Voorraad #<?php echo (int) $product['id']; ?></span><span><?php echo htmlspecialchars($product['leertype']); ?></span></div>
							<div class="specs">
								<div class="spec-row"><span>Dikte</span><span><?php echo (int) $product['dikteMM']; ?> mm</span></div>
								<div class="spec-row"><span>Maat</span><span><?php echo (int) $product['lengteCM']; ?> x <?php echo (int) $product['breedteCM']; ?> cm</span></div>
								<div class="spec-row"><span>Gewicht</span><span><?php echo htmlspecialchars((string) $product['gewichtG']); ?> g</span></div>
								<div class="spec-row"><span>Kleur</span><span><?php echo htmlspecialchars($product['kleur'] ?? ''); ?></span></div>
								<?php if (!empty($product['herkomst'])): ?>
									<div class="spec-row"><span>Herkomst</span><span><?php echo htmlspecialchars($product['herkomst']); ?></span></div>
									<div class="spec-row"><span>Ontvangstdatum</span><span><?php echo htmlspecialchars($product['datum']); ?></span></div>
								<?php endif; ?>
							</div>
							<div class="hoeveelheid-box">&euro;<?php echo number_format((float) $product['prijs'], 2); ?></div>
							<details class="change-editor" id="wijzig-<?php echo (int) $product['id']; ?>"<?php echo isset($_GET['bewerken']) && (int) $_GET['bewerken'] === (int) $product['id'] ? ' open' : ''; ?>>
								<summary>Gegevens wijzigen</summary>
								<form class="change-form" method="post">
									<input type="hidden" name="action" value="wijzig_stuk">
									<input type="hidden" name="voorraad_id" value="<?php echo (int) $product['id']; ?>">
									<?php foreach ($filterQuery as $naam => $waarde): ?>
										<input type="hidden" name="filter_<?php echo htmlspecialchars($naam); ?>" value="<?php echo htmlspecialchars($waarde); ?>">
									<?php endforeach; ?>
									<?php if (!empty($product['ontvangst_id'])): ?>
										<label>Herkomst
											<input class="form-input" type="text" name="herkomst" value="<?php echo htmlspecialchars($product['herkomst'], ENT_QUOTES, 'UTF-8'); ?>" required>
										</label>
										<label>Ontvangstdatum
											<input class="form-input" type="date" name="datum" value="<?php echo htmlspecialchars($product['datum'], ENT_QUOTES, 'UTF-8'); ?>" required>
										</label>
									<?php endif; ?>
									<label>Leertype
										<input class="form-input" type="text" name="leertype" value="<?php echo htmlspecialchars($product['leertype'], ENT_QUOTES, 'UTF-8'); ?>" required>
									</label>
									<label>Dikte (mm)
										<input class="form-input" type="number" name="dikteMM" min="0" value="<?php echo (int) $product['dikteMM']; ?>" required>
									</label>
									<label>Lengte (cm)
										<input class="form-input" type="number" name="lengteCM" min="0" value="<?php echo (int) $product['lengteCM']; ?>" required>
									</label>
									<label>Breedte (cm)
										<input class="form-input" type="number" name="breedteCM" min="0" value="<?php echo (int) $product['breedteCM']; ?>" required>
									</label>
									<label>Kleur
										<input class="form-input" type="text" name="kleur" value="<?php echo htmlspecialchars($product['kleur'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
									</label>
									<label>Prijs (&euro;)
										<input class="form-input" type="number" name="prijs" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $product['prijs'], ENT_QUOTES, 'UTF-8'); ?>" required>
									</label>
									<label>Gewicht (g)
										<input class="form-input" type="number" name="gewichtG" min="0" step="0.1" value="<?php echo htmlspecialchars((string) $product['gewichtG'], ENT_QUOTES, 'UTF-8'); ?>" required>
									</label>
									<?php if (!empty($product['ontvangst_id'])): ?>
										<label>Bruikbaarheid
											<input class="form-input" type="number" name="bruikbaarheid" min="0" step="0.1" value="<?php echo htmlspecialchars((string) ($product['bruikbaarheid'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" required>
										</label>
									<?php endif; ?>
									<button class="btn-primary change-save" type="submit">Wijzigingen opslaan</button>
								</form>
							</details>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</main>
	</div>
</body>
</html>
