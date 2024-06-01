import React from 'react';
import ReactDOM from 'react-dom';
import NavigationBar from '../components/Navbar';
import Footer from '../components/Footer';
import Container from 'react-bootstrap/Container';

const PrivacyPolicy = () => {
    return (
        <div>
            <NavigationBar />
            <Container>
                <div style={{ textAlign: "justify" }}>
                    <h1>Privacy Policy for TourKokan</h1>
                    <p>TourKokan is committed to protecting the privacy of its users. This Privacy Policy governs the manner in which TourKokan collects, uses, maintains, and discloses information collected from users (each, a "User") of the TourKokan mobile application ("App").</p>
                    <h2>Information We Collect</h2>
                    <p>The App may collect certain personally identifiable information from Users in a variety of ways, including, but not limited to, when Users visit our App, register on the App, and in connection with other activities, services, features, or resources we make available on our App. Users may be asked for, as appropriate, name, email address, profile picture, and other relevant information. We will collect personal identification information from Users only if they voluntarily submit such information to us. Users can always refuse to supply personally identifiable information, except that it may prevent them from engaging in certain App-related activities.</p>
                    <h2>Usage Data</h2>
                    <p>We may also collect information that your device sends whenever you visit our App ("Usage Data"). This Usage Data may include information such as your device's Internet Protocol ("IP") address, device type, device operating system version, the pages of our App that you visit, the time and date of your visit, the time spent on those pages, and other statistics.</p>
                    <h2>Location Data</h2>
                    <p>With your consent, we may collect and process information about your actual location. We use various technologies to determine location, including IP address, GPS, and other sensors that may provide information on nearby devices, Wi-Fi access points, and cell towers.</p>
                    <h2>Cookies and Tracking Technologies</h2>
                    <p>We use cookies and similar tracking technologies to track the activity on our App and hold certain information. Cookies are files with a small amount of data which may include an anonymous unique identifier. You can instruct your device to refuse all cookies or to indicate when a cookie is being sent. However, if you do not accept cookies, you may not be able to use some portions of our App.</p>
                    <h2>Use of Information</h2>
                    <p>TourKokan may collect and use Users' personal information for the following purposes:</p>
                    <ul>
                        <li>To improve customer service: Information you provide helps us respond to your customer service requests and support needs more efficiently.</li>
                        <li>To personalize user experience: We may use information in the aggregate to understand how our Users as a group use the services and resources provided on our App.</li>
                        <li>To improve our App: We continually strive to improve our App offerings based on the information and feedback we receive from you.</li>
                        <li>To send periodic emails: We may use the email address to respond to inquiries, questions, and/or other requests.</li>
                        <li>To process transactions: We may use the information Users provide about themselves when placing an order only to provide service to that order. We do not share this information with outside parties except to the extent necessary to provide the service.</li>
                        <li>To manage user accounts: We may use the information to manage user accounts and provide a seamless experience across devices and sessions.</li>
                    </ul>
                    <h2>How We Protect Your Information</h2>
                    <p>We adopt appropriate data collection, storage, and processing practices and security measures to protect against unauthorized access, alteration, disclosure, or destruction of your personal information, username, password, transaction information, and data stored on our App. Sensitive and private data exchange between the App and its Users happens over a secured communication channel and is encrypted and protected with digital signatures.</p>
                    <h2>Sharing Your Personal Information</h2>
                    <p>We do not sell, trade, or rent Users' personal identification information to others. We may share generic aggregated demographic information not linked to any personal identification information regarding visitors and users with our business partners, trusted affiliates, and advertisers for the purposes outlined above. We may share information with third-party service providers to help us operate our business and the App or administer activities on our behalf, such as sending out newsletters or surveys. We may share your information with these third parties for those limited purposes provided that you have given us your permission.</p>
                    <h2>Retention of Data</h2>
                    <p>We will retain your personal information only for as long as is necessary for the purposes set out in this Privacy Policy. We will retain and use your personal information to the extent necessary to comply with our legal obligations (for example, if we are required to retain your data to comply with applicable laws), resolve disputes, and enforce our legal agreements and policies.</p>
                    <h2>Changes to This Privacy Policy</h2>
                    <p>TourKokan has the discretion to update this privacy policy at any time. When we do, we will post a notification on the main page of our App. We encourage Users to frequently check this page for any changes to stay informed about how we are helping to protect the personal information we collect. You acknowledge and agree that it is your responsibility to review this privacy policy periodically and become aware of modifications.</p>
                    <h2>Your Acceptance of These Terms</h2>
                    <p>By using this App, you signify your acceptance of this policy. If you do not agree to this policy, please do not use our App. Your continued use of the App following the posting of changes to this policy will be deemed your acceptance of those changes.</p>
                    <h2>Contact Us</h2>
                    <p>If you have any questions about this Privacy Policy, the practices of this App, or your dealings with this App, please contact us at <a href="mailto:support@tourkokan.com">support@tourkokan.com</a>.</p>
                    <p>This document was last updated on 06th April 2024.</p>
                </div>
            </Container>
            <Footer />
        </div>
    )
}

export default PrivacyPolicy;

if (document.getElementById('PrivacyPolicy')) {
    ReactDOM.render(<PrivacyPolicy />, document.getElementById('PrivacyPolicy'));
}