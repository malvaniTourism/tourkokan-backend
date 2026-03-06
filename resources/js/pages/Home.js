import React from 'react';
import NavigationBar from '../components/Navbar';
import Carousel from '../components/Carousel';
import About from '../components/About';
import Contact from '../components/Contact';
import Footer from '../components/Footer';
import DeleteMyAccount from '../components/DeleteMyAccount ';
import Pricing from '../components/Pricing';

function Home() {
    return (
        <div style={{ paddingTop: "80px" }}>
            <NavigationBar />
            <Carousel />
            <div id="About">
                <About />
            </div>
            <div id="Pricing">
                <Pricing />
            </div>
            <div id="Contact" style={{ padding: "50px 0", backgroundColor: "#152F4F" }}>
                <Contact />
            </div>
            <div id="DeleteMyAccount">
                <DeleteMyAccount />
            </div>
            <Footer />
        </div>
    );
}

export default Home;
