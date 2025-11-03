$(document).ready(function () {
    // Get language from PHP
    let currentLang = 'en'; // Default fallback
    
    // Method 1: Get from PHP variable (recommended)
    // Add this to your HTML/PHP page: <script>var phpLang = '<?php echo lang(); ?>';</script>
    if (typeof phpLang !== 'undefined') {
        currentLang = phpLang;
    }
    // Method 2: Get from HTML lang attribute (if you're setting it via PHP)
    else if (document.documentElement.lang) {
        currentLang = document.documentElement.lang;
    }
    // Method 3: Get from URL parameter (if using lang.php URL switching)
    else {
        const urlParams = new URLSearchParams(window.location.search);
        currentLang = urlParams.get('lang') || 'en';
    }
    
    console.log("DEBUG: currentLang =", currentLang);
    console.log('Current language detected:', currentLang);
    
    // Language code mapping for DataTables CDN
    // DataTables uses specific language codes that might differ from yours
    const langMap = {
        'en': 'en-GB',
        'tr': 'tr',
        'fr': 'fr-FR',
        'ru': 'ru',
        'ar': 'ar'
    };
    
    // Set text direction for Arabic
    if (currentLang === 'ar') {
        document.documentElement.setAttribute('dir', 'rtl');
    } else {
        document.documentElement.setAttribute('dir', 'ltr');
    }
    
    // Get the appropriate language code for DataTables CDN
    const dataTablesLang = langMap[currentLang] || 'en-GB';
    
    console.log('DataTables language code:', dataTablesLang);
    
    // Initialize DataTable with CDN language file
    $('#example').DataTable({
        responsive: true,
        deferRender: true,
        language: {
            url: `https://cdn.datatables.net/plug-ins/1.13.7/i18n/${dataTablesLang}.json`
        },
        initComplete: function () {
            $('#example').css('display', 'table').css('visibility', 'visible');
        }
    });
});