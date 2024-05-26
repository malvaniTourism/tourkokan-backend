import React from 'react';
import NavigationBar from '../components/Navbar';
import Carousel from '../components/Carousel';
import About from '../components/About';
import Contact from '../components/Contact';
import Footer from '../components/Footer';

function Home() {
    return (
        <div>
            <NavigationBar />
            <Carousel />
            <div id='About'>
                <About />
            </div>
            <div id='Contact' style={{padding: 50, backgroundColor: "#152F4F"}}>
                <Contact />
            </div>

            <Footer />
        </div>
    );
}

export default Home;
