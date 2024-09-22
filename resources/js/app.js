import React from 'react';
import ReactDOM from 'react-dom';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import Home from './pages/Home';
import About from './pages/AboutUs';
import 'bootstrap/dist/css/bootstrap.min.css';
import PrivacyPolicy from './pages/PrivacyPolicy';
import RegistrationPage from './pages/RegistrationPage';
import Terms from './pages/Terms';

function App() {
    return (
        <Router>
            <div>
                <Routes>
                    <Route exact path="/" element={<Home />} />
                    <Route path="/about" element={<About />} />
                    <Route path="/PrivacyPolicy" element={<PrivacyPolicy />} />
                    <Route path="/Register" element={<RegistrationPage />} />
                    <Route path="/Terms/:app?" element={<Terms />} />
                </Routes>
            </div>
        </Router>
    );
}

ReactDOM.render(<App />, document.getElementById('app'));
