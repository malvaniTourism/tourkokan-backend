import React from 'react';
import About from '../components/About';

function AboutUs() {
    return (
        <div style={{ paddingTop: "100px" }}> {/* Adjust padding based on navbar height */}
            <h1>About Page</h1>
            <About />
        </div>
    );
}

export default AboutUs;
