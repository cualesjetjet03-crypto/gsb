<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Order - GSB Airconditioning</title>
    
    <link rel="stylesheet" href="css/GSB-Homepage.css">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="icon" type="image/png" href="favicon.png">
    
    <style>
        .bulk-container {
            max-width: 800px;
            margin: 60px auto;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 5px solid #d31b27;
        }
        .bulk-container h1 {
            color: #0020C2;
            font-size: 32px;
            margin-bottom: 20px;
        }
        .bulk-container p.desc {
            color: #475569;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 40px;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
            text-align: left;
        }
        .contact-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .contact-card h3 {
            color: #0f172a;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .contact-card p, .contact-card a {
            color: #0020C2;
            font-weight: 600;
            text-decoration: none;
            font-size: 15px;
        }
        .map-container iframe {
            width: 100%;
            height: 400px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .back-btn {
            display: inline-block;
            margin-top: 30px;
            margin-bottom: 10px;
            padding: 12px 24px;
            background: #0020C2;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="bulk-container">
        <h1>Whoa, that's a bulk order!</h1>
        <p class="desc">
            We're thrilled you chose GSB Airconditioning! However, because we only have a dedicated team of 10 expert technicians, our automated system can't instantly process 5 or more units at once. <br><br>
            To ensure you get the best service, please contact us directly or visit our store so we can properly arrange your bulk order and installation schedule!
        </p>

        <!-- Add this link inside the <head> tag of your HTML file to load the icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <div class="contact-grid">
            <div class="contact-card">
                <h3><i class="fa-solid fa-phone" style="color: #333; margin-right: 8px;"></i> Phone</h3>
                <p>09090338676</p>
            </div>
            <div class="contact-card">
                <h3><i class="fa-solid fa-envelope" style="color: #333; margin-right: 8px;"></i> Email</h3>
                <p>jpelota29@gmail.com</p>
            </div>
            <div class="contact-card">
                <h3><i class="fa-solid fa-share-nodes" style="color: #333; margin-right: 8px;"></i> Social Media</h3>
                <a href="#" style="text-decoration: none; color: #1877F2;"><i class="fa-brands fa-facebook"></i> Facebook Page</a><br>
                <a href="#" style="text-decoration: none; color: #E4405F;"><i class="fa-brands fa-instagram"></i> Instagram</a>
            </div>  
        </div>

        <h2 style="margin-bottom: 20px; color:#0f172a;"> Visit Our Store</h2>
        <div class="map-container">
            <!-- Google Maps Embed centered on Calamba City -->
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d123730.07632617637!2d121.0506086705704!3d14.205240212953258!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd631376f92025%3A0xe4a19b489d813735!2sCalamba%2C%20Laguna%2C%20Philippines!5e0!3m2!1sen!2sph!4v1704200000000!5m2!1sen!2sph" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        
        <a href="products.php" class="back-btn">Return to Products</a>
    </div>

    <!-- =========================================================
         FOOTER
    ========================================================= -->
    <?php include 'footer.php'; ?>

</body>
</html>