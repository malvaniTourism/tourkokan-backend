import React, { useState } from 'react';
import NavigationBar from '../components/Navbar';
import Footer from '../components/Footer';
import Register from '../components/Register';


const Registration = () => {
    return (
        <div>
            <NavigationBar />
            <Register/>
            <Footer />
        </div>
    );
};

export default Registration;
