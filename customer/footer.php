<!-- FontAwesome for Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<footer class="site-footer" id="contact">
    <div class="footer-container">
        <!-- Brand Column -->
        <div class="footer-col brand-col">
            <div class="footer-logo">
                <img src="./GSB homepage Image/GSB Logo.png" alt="GSB Air Conditioning Services Logo" onerror="this.src='../images/no-image.png'">
                <h3>GSB Air Conditioning Services</h3>
            </div>
            <p>Product browsing, ordering, installation, repair, and cleaning in one simple website.</p>
        </div>
        
        <!-- Shop Column -->
        <div class="footer-col">
            <h4>Shop</h4>
            <ul>
                <li><a href="products.php">All Aircons</a></li>
                <li><a href="products.php?category=split">Split Type</a></li>
                <li><a href="products.php?category=window">Window Type</a></li>
            </ul>
        </div>
        
        <!-- Services Column -->
        <div class="footer-col">
            <h4>Services</h4>
            <ul>
                <li><a href="book_service.php?service=installation">Installation</a></li>
                <li><a href="book_service.php?service=repair">Repair</a></li>
                <li><a href="book_service.php?service=cleaning">Cleaning</a></li>
                <li><a href="book_service.php?service=checkup">Check-up</a></li>
            </ul>
        </div>
        
        <!-- Connect With Us Column -->
        <div class="footer-col connect-col">
            <h4 class="connect-title">Connect with us</h4>
            
            <div class="social-icons">
                <a href="https://www.facebook.com/share/1GiVTFDBWF/"><i class="fa-brands fa-facebook"></i></a>
                <a href="#"><i class="fa-brands fa-viber"></i></a>
                <a href="#"><i class="fa-solid fa-envelope"></i></a>
            </div>
            
            <hr class="connect-divider">
            
            <p class="connect-subtitle">Online Personal Shopper</p>
            
            <!-- ADDED "collapsed" CLASS HERE BY DEFAULT -->
            <ul class="connect-list collapsed" id="connectList">
                <li><i class="fa-solid fa-phone"></i> (02) 8811-8811</li>
                <li><i class="fa-solid fa-mobile-screen"></i> 09156286296 | 09452190274</li>
                <li><i class="fa-brands fa-facebook-messenger"></i> Messenger : GSB Air-conditioning/ Refrigeration Services</li>
                <li><i class="fa-brands fa-viber" style="font-size:16px;"></i> Viber: 09156286296</li>
                <li><i class="fa-brands fa-whatsapp" style="font-size:16px;"></i> Whatsapp: 09452190274</li>
                <li><i class="fa-solid fa-envelope" style="font-size:16px;"></i> Gmail: officialgsbairconservice@gmail.com</li>
            </ul>
            
            <p class="connect-hours">Store's Open at 8am - 6pm</p>
            
            <!-- CHANGED INITIAL TEXT TO "See more" -->
            <a href="javascript:void(0);" class="btn-see-less" id="toggleContactBtn" onclick="toggleConnectList()">See more</a>
        </div>
    </div>
    
    <div class="footer-bottom">
        <p><?php echo date('Y'); ?> GSB Air Conditioning Services.</p>
    </div>
</footer>

<!-- JavaScript for toggling See less / See more -->
<script>
function toggleConnectList() {
    const list = document.getElementById('connectList');
    const btn = document.getElementById('toggleContactBtn');
    
    if (list.classList.contains('collapsed')) {
        // Expand the list
        list.classList.remove('collapsed');
        btn.innerText = 'See less';
    } else {
        // Collapse the list
        list.classList.add('collapsed');
        btn.innerText = 'See more';
    }
}
</script>