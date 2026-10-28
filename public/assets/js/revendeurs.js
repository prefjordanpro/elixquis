(() => {
  'use strict';
  const cards = [...document.querySelectorAll('[data-retailer]')];
  const markers = new Map();
  let map;
  if (window.L) {
    map = L.map('retailers-map', { scrollWheelZoom: false }).setView([46.6, 2.5], 6);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    cards.forEach(card => {
      if (!card.hasAttribute('data-lat')) return;
      const lat = Number(card.dataset.lat), lng = Number(card.dataset.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
      // Clone safe, server-escaped DOM nodes. Never interpolate CMS text into HTML.
      const popup = document.createElement('div');
      ['.retailer-name', '.retailer-address', '.retailer-phone', '.retailer-website'].forEach(selector => {
        const node = card.querySelector(selector);
        if (node) popup.append(node.cloneNode(true));
      });
      const marker = L.marker([lat, lng], { title: card.querySelector('.retailer-name').textContent })
        .addTo(map).bindPopup(popup, { maxWidth: 280 });
      markers.set(card, marker);
      const button = card.querySelector('.retailer-locate');
      button.hidden = false;
      button.addEventListener('click', () => {
        map.setView(marker.getLatLng(), 13); marker.openPopup();
        const element = document.getElementById('retailers-map');
        element.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
        element.focus({ preventScroll: true });
      });
    });
    document.getElementById('map-status').textContent = markers.size
      ? 'Sélectionnez un repère pour découvrir une adresse. Toutes les adresses figurent aussi dans la liste.'
      : 'Les adresses seront ajoutées à la carte dès que leurs coordonnées seront disponibles.';
  }
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
  const search = document.getElementById('retailers-search');
  if (cards.length) document.querySelector('.retailers-search').hidden = false;
  search.addEventListener('input', () => {
    const query = normalize(search.value.trim());
    let count = 0;
    cards.forEach(card => {
      const visible = normalize(`${card.dataset.city} ${card.dataset.postcode}`).includes(query);
      card.hidden = !visible;
      if (visible) count++;
      const marker = markers.get(card);
      if (marker) { if (visible) marker.addTo(map); else map.removeLayer(marker); }
    });
    document.getElementById('retailers-results').textContent = `${count} adresse${count > 1 ? 's' : ''}`;
    document.getElementById('retailers-empty').hidden = count !== 0;
  });
})();
