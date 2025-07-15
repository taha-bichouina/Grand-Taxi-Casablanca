<?php
// Connexion à la base de données
$host = 'localhost';
$db = 'taxi_casablanca';
$user = 'root';
$pass = '';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);

// Récupérer toutes les lignes avec leurs points de trajet
$stmt = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC");
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

$linesWithPoints = [];
foreach ($lines as $line) {
    $stmtPts = $pdo->prepare("SELECT latitude, longitude FROM trajets WHERE line_id = ? ORDER BY ordre ASC");
    $stmtPts->execute([$line['id']]);
    $points = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

    // Formater les points pour JS : [[lat,lng], [lat,lng], ...]
    $formattedPoints = array_map(fn($pt) => [(float)$pt['latitude'], (float)$pt['longitude']], $points);

    $linesWithPoints[] = [
        'id' => $line['id'],
        'name' => $line['name'],
        'color' => $line['color'],
        'points' => $formattedPoints,
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Carte des Grands Taxis - Casablanca</title>
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css"
  />
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />

<style>
  body {
    margin: 0;
    font-family: 'Poppins', sans-serif;
    background-color: #0c1a2b;
    color: #f1f5f9;
  }

  .header {
    text-align: center;
    padding: 2rem 1rem;
    background-color: #162740;
    border-bottom: 2px solid #23344e;
  }

  h1 {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    animation: fadeInDown 1s ease;
  }

  .container {
    display: flex;
    flex-wrap: wrap;
    padding: 1rem;
  }

  .sidebar {
    flex: 1;
    min-width: 280px;
    max-width: 350px;
    padding: 1rem;
    background-color: #131f36;
    border-right: 2px solid #23344e;
    animation: fadeInLeft 0.8s ease;
  }

  .map-container {
    flex: 3;
    padding: 1rem;
    animation: fadeIn 1.2s ease;
  }

  #map {
    width: 100%;
    height: 500px;
    border: 1px solid #29497c;
    border-radius: 10px;
  }

  .search-section h3,
  .route-list h3 {
    margin-top: 0;
    font-size: 1.2rem;
    color: #66aaff;
  }

  .search-input {
    width: 100%;
    padding: 8px 12px;
    margin-bottom: 10px;
    border-radius: 6px;
    background-color: #1f2d41;
    border: 1px solid #29497c;
    color: #f1f5f9;
    transition: border-color 0.3s;
  }

  .search-input:focus {
    outline: none;
    border-color: #3388ff;
  }

  .filter-btn {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
    background-color: #3388ff;
    border: none;
    border-radius: 6px;
    color: white;
    font-weight: bold;
    cursor: pointer;
    transition: background-color 0.3s;
  }

  .filter-btn:hover {
    background-color: #1d70d1;
  }

  .route-item {
    background-color: #1f2d41;
    border-left: 6px solid #3388ff;
    padding: 10px;
    margin-bottom: 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.3s, background-color 0.3s;
  }

  .route-item:hover {
    transform: translateX(4px);
    background-color: #23344e;
  }

  .suggestions {
    position: absolute;
    background-color: #1f2d41;
    border: 1px solid #29497c;
    border-radius: 4px;
    max-height: 150px;
    overflow-y: auto;
    z-index: 10;
    width: 100%;
  }

  .suggestion-item {
    padding: 8px;
    cursor: pointer;
    border-bottom: 1px solid #29497c;
    transition: background-color 0.3s;
  }

  .suggestion-item:hover {
    background-color: #29497c;
  }

  /* Animations */
  @keyframes fadeIn {
    from { opacity: 0 }
    to { opacity: 1 }
  }

  @keyframes fadeInLeft {
    from { transform: translateX(-30px); opacity: 0 }
    to { transform: translateX(0); opacity: 1 }
  }

  @keyframes fadeInDown {
    from { transform: translateY(-20px); opacity: 0 }
    to { transform: translateY(0); opacity: 1 }
  }

  /* Responsive */
  @media (max-width: 768px) {
    .container { flex-direction: column }
    .sidebar { border-right: none; border-bottom: 2px solid #23344e }
  }
</style>
</head>
<body>
  <div class="header">
    <h1>🚖 Carte des Grands Taxis - Casablanca</h1>
    <p>Trouvez facilement les trajets des taxis blancs</p>
  </div>
  <div class="container">
    <div class="sidebar">
      <div class="search-section">
        <h3>Rechercher un trajet</h3>
        <div style="position: relative">
          <input
            type="text"
            id="startPoint"
            class="search-input"
            placeholder="Point de départ..."
          />
          <div id="startSuggestions" class="suggestions"></div>
        </div>
        <div style="position: relative">
          <input
            type="text"
            id="endPoint"
            class="search-input"
            placeholder="Destination..."
          />
          <div id="endSuggestions" class="suggestions"></div>
        </div>
        <button class="filter-btn" onclick="searchRouteFromInputs()" font-family:  sans-serif>
          Rechercher
        </button>
        <button class="filter-btn" onclick="useCurrentLocation()">
          📍 Ma position
        </button>
      </div>
      <div class="route-list" style="margin-top: 2rem;">
        <h3>Trajets disponibles</h3>
        <?php if (count($linesWithPoints) === 0): ?>
          <p>Aucune ligne enregistrée pour le moment.</p>
        <?php else: ?>
          <?php foreach ($linesWithPoints as $line): ?>
            <div class="route-item" onclick="zoomToLine(<?= $line['id'] ?>)" style="border-left-color: <?= htmlspecialchars($line['color']) ?>">
              <?= htmlspecialchars($line['name']) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="map-container">
      <div id="map"></div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
  <script>
    const MAPBOX_TOKEN =
      "pk.eyJ1IjoidGFoYWJpY2hvIiwiYSI6ImNtZDRsdTg3ZDBmb2Iya3NjMmRvaHJzc2QifQ.kEde5kOzGqvN_g-wlczYCg";

    let map, currentRoute;
    const taxiLines = <?= json_encode($linesWithPoints, JSON_UNESCAPED_UNICODE) ?>;
    let polylines = {};
    let typingTimers = {};

    document.addEventListener("DOMContentLoaded", () => {
      map = L.map("map").setView([33.5731, -7.5898], 12);
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
      }).addTo(map);

      // Afficher toutes les lignes
      taxiLines.forEach(line => {
        if (line.points.length > 1) {
          const polyline = L.polyline(line.points, {
            color: line.color,
            weight: 4,
            opacity: 0.8,
          }).addTo(map);
          polylines[line.id] = polyline;
        }
      });

      setupAutocomplete("startPoint", "startSuggestions");
      setupAutocomplete("endPoint", "endSuggestions");
    });

    function zoomToLine(lineId) {
      const polyline = polylines[lineId];
      if (!polyline) return alert("Trajet introuvable.");
      map.fitBounds(polyline.getBounds(), { padding: [50, 50] });
    }

    function setupAutocomplete(inputId, suggestionsId) {
      const input = document.getElementById(inputId);
      const box = document.getElementById(suggestionsId);

      input.addEventListener("input", () => {
        const query = input.value.trim();
        clearTimeout(typingTimers[inputId]);

        if (query.length < 2) return (box.style.display = "none");

        box.innerHTML = "<div class='suggestion-item'>Chargement...</div>";
        box.style.display = "block";

        typingTimers[inputId] = setTimeout(async () => {
          if (cache[query]) {
            showSuggestions(cache[query], input, box);
            return;
          }

          // Pas de bbox, mais proximité Casablanca centre pour favoriser les résultats proches
          const proximity = "-7.5898,33.5731";
          const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(
            query
          )}.json?access_token=${MAPBOX_TOKEN}&limit=5&language=fr&proximity=${proximity}`;

          try {
            const res = await fetch(url);
            const data = await res.json();

            cache[query] = data.features;
            showSuggestions(data.features, input, box);
          } catch (error) {
            console.error("Erreur Mapbox:", error);
            box.innerHTML =
              "<div class='suggestion-item'>Erreur de recherche</div>";
          }
        }, 300);
      });

      input.addEventListener("blur", () =>
        setTimeout(() => (box.style.display = "none"), 150)
      );
    }

    function showSuggestions(features, input, box) {
      box.innerHTML = "";

      // Filtrer uniquement Casablanca ou Mohammedia dans le contexte
      const filtered = features.filter((f) => {
        if (!f.context) return false;
        return f.context.some(
          (c) => c.text === "Casablanca" || c.text === "Mohammedia"
        );
      });

      if (!filtered.length) {
        box.innerHTML = "<div class='suggestion-item'>Aucun résultat</div>";
        return;
      }

      filtered.forEach((place) => {
        const div = document.createElement("div");
        div.className = "suggestion-item";
        div.textContent = place.place_name;
        div.onclick = () => {
          input.value = place.place_name;
          box.style.display = "none";
        };
        box.appendChild(div);
      });

      box.style.display = "block";
    }

    async function searchRouteFromInputs() {
      const start = document.getElementById("startPoint").value;
      const end = document.getElementById("endPoint").value;
      if (start && end) showRouteByName(start, end);
    }

    async function showRouteByName(startName, endName) {
      try {
        // Recherche sans bbox ici aussi pour éviter blocage
        const [startRes, endRes] = await Promise.all([
          fetch(
            `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(
              startName
            )}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`
          ),
          fetch(
            `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(
              endName
            )}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`
          ),
        ]);

        const startData = await startRes.json();
        const endData = await endRes.json();

        if (!startData.features.length || !endData.features.length) {
          alert("Coordonnées introuvables.");
          return;
        }

        const start = startData.features[0].center;
        const end = endData.features[0].center;

        const routeRes = await fetch(
          `https://router.project-osrm.org/route/v1/driving/${start[0]},${start[1]};${end[0]},${end[1]}?overview=full&geometries=geojson`
        );
        const routeJson = await routeRes.json();

        const coords = routeJson.routes[0].geometry.coordinates.map(
          ([lon, lat]) => [lat, lon]
        );

        if (currentRoute) map.removeLayer(currentRoute);
        currentRoute = L.polyline(coords, { color: "#e67e22", weight: 5 }).addTo(
          map
        );
        map.fitBounds(currentRoute.getBounds());
      } catch (error) {
        console.error("Erreur itinéraire:", error);
        alert("Impossible de tracer le trajet.");
      }
    }

    function useCurrentLocation() {
      if (!navigator.geolocation) return alert("Géolocalisation non supportée");

      navigator.geolocation.getCurrentPosition(
        (pos) => {
          const lat = pos.coords.latitude;
          const lon = pos.coords.longitude;
          L.marker([lat, lon])
            .addTo(map)
            .bindPopup("📍 Vous êtes ici")
            .openPopup();
          map.setView([lat, lon], 13);
          document.getElementById("startPoint").value = `${lat}, ${lon}`;
        },
        () => alert("Impossible d'obtenir la position")
      );
    }
  </script>
</body>
</html>