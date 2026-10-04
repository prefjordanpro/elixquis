if (window.feather) feather.replace();
const productPlaceholderUrl = new URL('../../images/produit-indisponible.svg', document.currentScript.src).href;

document.querySelectorAll('img[src*="/uploads/"]').forEach((image) => {
    const fallback = () => {
        image.onerror = null;
        image.src = productPlaceholderUrl;
    };
    image.addEventListener('error', fallback, { once: true });
    if (image.complete && image.naturalWidth === 0) fallback();
});
