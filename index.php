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
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      font-family: "Segoe UI", sans-serif;
      background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);
      color: #2c3e50;
      min-height: 100vh;
    }
    .header {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      padding: 1rem 2rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    .header h1 {
      font-size: 2rem;
      font-weight: 700;
    }
    .header p {
      color: #555;
    }
    .container {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      padding: 1rem;
    }
    .sidebar {
      flex: 1 1 320px;
      background: white;
      border-radius: 15px;
      padding: 1.5rem;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
      position: relative;
      max-height: 600px;
      overflow-y: auto;
    }
    .map-container {
      flex: 2 1 600px;
      border-radius: 15px;
      overflow: hidden;
      height: 600px;
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    #map {
      width: 100%;
      height: 100%;
    }
    .search-input {
      width: 100%;
      padding: 0.8rem;
      border: 1px solid #ddd;
      border-radius: 10px;
      margin-bottom: 0.5rem;
    }
    .search-input:focus {
      outline: none;
      border-color: #5dade2;
      box-shadow: 0 0 5px rgba(93, 173, 226, 0.4);
    }
    .suggestions {
      position: absolute;
      z-index: 999;
      width: calc(100% - 3rem);
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 8px;
      max-height: 200px;
      overflow-y: auto;
    }
    .suggestion-item {
      padding: 0.8rem;
      cursor: pointer;
      border-bottom: 1px solid #eee;
    }
    .suggestion-item:hover {
      background: #f9f9f9;
    }
    .route-item {
      background: #fdfdfd;
      padding: 1rem;
      border-radius: 12px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      border-left: 4px solid #5dade2;
      margin-bottom: 1rem;
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .route-item:hover {
      background: #f9f9f9;
      transform: scale(1.02);
    }
    .filter-btn {
      padding: 0.6rem 1rem;
      border-radius: 20px;
      margin: 0.3rem 0.2rem 0.3rem 0;
      background: #5dade2;
      color: white;
      border: none;
      cursor: pointer;
      transition: 0.3s;
    }
    .filter-btn:hover,
    .filter-btn.active {
      background: #3498db;
    }
    @media (max-width: 768px) {
      .container {
        flex-direction: column;
      }
      .map-container {
        height: 400px;
      }
      .sidebar {
        max-height: none;
      }
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
        <button class="filter-btn" onclick="searchRouteFromInputs()">
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
