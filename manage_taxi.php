<?php
// Connexion PDO
$dsn = "mysql:host=localhost;dbname=taxi_casablanca;charset=utf8mb4";
$user = "root";
$pass = "";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    die("Erreur de connexion à la base : " . $e->getMessage());
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $color = trim($_POST['color'] ?? '#3388ff');
    $pointsJson = $_POST['points'] ?? '[]';
    $departureIndex = isset($_POST['departureIndex']) ? intval($_POST['departureIndex']) : -1;
    $arrivalIndex = isset($_POST['arrivalIndex']) ? intval($_POST['arrivalIndex']) : -1;

    $points = json_decode($pointsJson, true);

    if ($name === '' || count($points) < 2 || $departureIndex < 0 || $arrivalIndex < 0) {
        $message = "❌ Veuillez remplir tous les champs et tracer au moins 2 points.";
    } else if ($departureIndex >= count($points) || $arrivalIndex >= count($points)) {
        $message = "❌ Index de départ ou d'arrivée invalide.";
    } else if ($departureIndex == $arrivalIndex) {
        $message = "❌ Le départ et l'arrivée doivent être différents.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO taxi_line (name, color) VALUES (?, ?)");
            $stmt->execute([$name, $color]);
            $line_id = $pdo->lastInsertId();

            $stmtPoint = $pdo->prepare("INSERT INTO trajets (line_id, latitude, longitude, ordre) VALUES (?, ?, ?, ?)");

            foreach ($points as $index => $pt) {
                $lat = floatval($pt['lat'] ?? 0);
                $lng = floatval($pt['lng'] ?? 0);
                $stmtPoint->execute([$line_id, $lat, $lng, $index]);
            }

            $message = "✅ Ligne enregistrée avec succès !";
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
        }
    }
}

$lines = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter une ligne de taxi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #map { height: 500px; border-radius: 0.5rem; }
        .color-input {
            width: 60px;
            height: 40px;
            border: none;
            cursor: pointer;
        }
        .point-list {
            max-height: 150px;
            overflow-y: auto;
            margin-top: 0.5rem;
            background: #f8f9fa;
            border-radius: 0.25rem;
            padding: 0.5rem;
        }
        .point-list li {
            cursor: pointer;
            padding: 3px 6px;
            border-radius: 0.3rem;
        }
        .point-list li.selected-departure {
            background-color: #198754;
            color: white;
        }
        .point-list li.selected-arrival {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body class="bg-light">
<div class="container my-4">
    <h2 class="text-center mb-4">➕ Ajouter une ligne de taxi - Casablanca</h2>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" id="taxiForm">
        <div class="mb-3">
            <label for="name" class="form-label">Nom de la ligne</label>
            <input type="text" class="form-control" id="name" name="name" placeholder="Exemple : Derb Sultan - Bernoussi" required>
        </div>

        <div class="mb-3">
            <label for="color" class="form-label">Couleur</label>
            <input type="color" class="form-control color-input" id="color" name="color" value="#3388ff" required>
        </div>

        <div class="mb-3">
            <label>Itinéraire (cliquez sur la carte)</label>
            <div id="map"></div>
        </div>

        <small class="text-muted">Cliquez sur les points pour choisir le départ (vert) et l'arrivée (rouge)</small>
        <ul id="pointsList" class="point-list list-unstyled"></ul>

        <input type="hidden" name="points" id="pointsInput">
        <input type="hidden" name="departureIndex" id="departureIndex">
        <input type="hidden" name="arrivalIndex" id="arrivalIndex">

        <button type="submit" class="btn btn-primary mt-3">💾 Enregistrer</button>
    </form>

    <hr class="my-5">

    <h3>Lignes existantes</h3>
    <ul class="list-group">
        <?php foreach ($lines as $line): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><span style="width:20px;height:20px;background:<?= $line['color'] ?>;display:inline-block;border-radius:4px;margin-right:10px;"></span><?= htmlspecialchars($line['name']) ?></span>
                <a href="#" class="btn btn-sm btn-outline-secondary disabled">🗺️ Voir (à implémenter)</a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let map = L.map('map').setView([33.5731, -7.5898], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(map);

    let points = [];
    let polyline = L.polyline(points, { color: document.getElementById('color').value }).addTo(map);
    let departureIndex = -1;
    let arrivalIndex = -1;

    function updateList() {
        const list = document.getElementById('pointsList');
        list.innerHTML = '';
        points.forEach((pt, i) => {
            const li = document.createElement('li');
            li.textContent = `Point ${i+1}: ${pt.lat.toFixed(5)}, ${pt.lng.toFixed(5)}`;
            if (i === departureIndex) li.classList.add('selected-departure');
            if (i === arrivalIndex) li.classList.add('selected-arrival');

            li.addEventListener('click', () => {
                if (departureIndex === -1) {
                    departureIndex = i;
                } else if (arrivalIndex === -1 && i !== departureIndex) {
                    arrivalIndex = i;
                } else {
                    departureIndex = i;
                    arrivalIndex = -1;
                }
                updateList();
                updateHidden();
            });

            list.appendChild(li);
        });
        updateHidden();
    }

    function updateHidden() {
        document.getElementById('pointsInput').value = JSON.stringify(points);
        document.getElementById('departureIndex').value = departureIndex;
        document.getElementById('arrivalIndex').value = arrivalIndex;
    }

    map.on('click', function(e) {
        points.push(e.latlng);
        polyline.setLatLngs(points);
        updateList();
    });

    document.getElementById('color').addEventListener('input', function () {
        polyline.setStyle({ color: this.value });
    });

    updateList();
</script>
</body>
</html>
