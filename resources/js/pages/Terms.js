import React from 'react';
import ReactDOM from 'react-dom';
import NavigationBar from '../components/Navbar';
import Footer from '../components/Footer';
import Container from 'react-bootstrap/Container';
import { useParams } from 'react-router-dom';

const Terms = () => {
    const { app } = useParams();

    return (
        <div>
             {!app && <NavigationBar />} 
            <Container>
                <h1>Terms and Conditions for TourKokan</h1>
                <p>Welcome to TourKokan! These terms and conditions outline the rules and regulations for the use of the TourKokan mobile application ("App"). By accessing this App, we assume you accept these terms and conditions. Do not continue to use TourKokan if you do not agree to take all of the terms and conditions stated on this page.</p>
                <h2>License</h2>
                <p>Unless otherwise stated, TourKokan and/or its licensors own the intellectual property rights for all material on TourKokan. All intellectual property rights are reserved. You may access this from TourKokan for your own personal use subjected to restrictions set in these terms and conditions.</p>
                <h2>User Accounts</h2>
                <p>When you create an account with us, you must provide us with information that is accurate, complete, and current at all times. Failure to do so constitutes a breach of the Terms, which may result in immediate termination of your account on our App.</p>
                <h2>Prohibited Activities</h2>
                <p>You are specifically restricted from all of the following:
                    <ul>
                        <li>Publishing any App material in any other media without permission.</li>
                        <li>Selling, sublicensing, and/or otherwise commercializing any App material.</li>
                        <li>Publicly performing and/or showing any App material without permission.</li>
                        <li>Using this App in any way that is or may be damaging to this App.</li>
                        <li>Using this App in any way that impacts user access to this App.</li>
                        <li>Using this App contrary to applicable laws and regulations, or in any way that may cause harm to the App, or to any person or business entity.</li>
                        <li>Engaging in any data mining, data harvesting, data extracting, or any other similar activity in relation to this App.</li>
                        <li>Using this App to engage in any advertising or marketing without permission.</li>
                    </ul>
                </p>
                <h2>Limitation of Liability</h2>
                <p>In no event shall TourKokan, nor any of its officers, directors, and employees, be held liable for anything arising out of or in any way connected with your use of this App whether such liability is under contract. TourKokan, including its officers, directors, and employees, shall not be held liable for any indirect, consequential, or special liability arising out of or in any way related to your use of this App.</p>
                <h2>Indemnification</h2>
                <p>You hereby indemnify to the fullest extent TourKokan from and against any and/or all liabilities, costs, demands, causes of action, damages, and expenses arising in any way related to your breach of any of the provisions of these Terms.</p>
                <h2>Severability</h2>
                <p>If any provision of these Terms is found to be invalid under any applicable law, such provisions shall be deleted without affecting the remaining provisions herein.</p>
                <h2>Variation of Terms</h2>
                <p>TourKokan is permitted to revise these Terms at any time as it sees fit, and by using this App you are expected to review these Terms on a regular basis.</p>
                <h2>Assignment</h2>
                <p>The TourKokan is allowed to assign, transfer, and subcontract its rights and/or obligations under these Terms without any notification. However, you are not allowed to assign, transfer, or subcontract any of your rights and/or obligations under these Terms.</p>
                <h2>Entire Agreement</h2>
                <p>These Terms constitute the entire agreement between TourKokan and you in relation to your use of this App, and supersede all prior agreements and understandings.</p>
                <h2>Governing Law & Jurisdiction</h2>
                <p>These Terms will be governed by and interpreted in accordance with the laws of the State/Country, and you submit to the non-exclusive jurisdiction of the state and federal courts located in State/Country for the resolution of any disputes.</p>
                <h2>Contact Us</h2>
                <p>If you have any questions about these Terms, please contact us at <a href="mailto:support@tourkokan.com">support@tourkokan.com</a>.</p>
                <p>This document was last updated on 06th April 2024.</p>
            </Container>
            {!app && <Footer />} 
        </div>
    )
}

export default Terms;

if (document.getElementById('Terms')) {
    ReactDOM.render(<Terms />, document.getElementById('Terms'));
}