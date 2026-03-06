import React from 'react';
import Container from 'react-bootstrap/Container';
import Navbar from 'react-bootstrap/Navbar';
import { Button, Nav } from 'react-bootstrap';
import "./styles.css";

function NavigationBar() {
    return (
        <Navbar fixed="top" bg="light" expand="lg" className="bg-body-tertiary shadow-sm">
            <Container>
                <Navbar.Brand href="/" style={{ display: "flex", alignItems: "center" }}>
                    <img
                        alt="TourKokan"
                        src="/logo.png"
                        height="60"
                        className="d-inline-block align-top"
                    />
                </Navbar.Brand>
                <Navbar.Toggle aria-controls="basic-navbar-nav" />
                <Navbar.Collapse id="basic-navbar-nav">
                    <Nav className="mx-auto justify-content-center custom-nav text-center">
                        <Nav.Link href="/" className="mx-2">Home</Nav.Link>
                        <Nav.Link href="/#About" className="mx-2">About Us</Nav.Link>
                        <Nav.Link href="/#Contact" className="mx-2">Contact Us</Nav.Link>
                    </Nav>
                    <div className="d-flex justify-content-center mt-2 mt-lg-0">
                        <a
                            href="https://play.google.com/store/apps/details?id=com.tourkokan&pcampaignid=web_share"
                            target="_blank"
                            rel="noreferrer"
                            style={{
                                display: "inline-flex",
                                alignItems: "center",
                                gap: "10px",
                                backgroundColor: "transparent",
                                borderRadius: "8px",
                                padding: "8px 16px",
                                textDecoration: "none",
                                border: "1px solid #aaa",
                                minWidth: "150px",
                            }}
                        >
                            <img src="/playstore.webp" alt="Google Play" style={{ width: "28px", height: "28px" }} />
                            <div style={{ lineHeight: 1.2 }}>
                                <div style={{ fontSize: "10px", color: "#555", letterSpacing: "0.5px" }}>GET IT ON</div>
                                <div style={{ fontSize: "16px", fontWeight: "600", color: "#000" }}>Google Play</div>
                            </div>
                        </a>
                    </div>
                </Navbar.Collapse>
            </Container>
        </Navbar>
    );
}

export default NavigationBar;
