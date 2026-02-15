import React from 'react';
import NavigationBar from '../components/Navbar';
import Contact from '../components/Contact';
import Footer from '../components/Footer';

const ContactUs = () => {
    return (
        <div style={{ paddingTop: "100px" }}>
            <NavigationBar />
            {/* Reusing the dark background style to match the Contact component's text color */}
            <div className="py-5" style={{ backgroundColor: "#152F4F", minHeight: "calc(100vh - 300px)" }}>
                <Contact />
            </div>
            <Footer />
        </div>
    );
}

export default ContactUs;