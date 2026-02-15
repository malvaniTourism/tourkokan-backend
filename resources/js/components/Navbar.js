import React, { useState } from 'react';
import Container from 'react-bootstrap/Container';
import Navbar from 'react-bootstrap/Navbar';
import { Form, Button, Nav, Modal } from 'react-bootstrap';
import "./styles.css"

function NavigationBar() {
    const [show, setShow] = useState(false);

    const handleClose = () => setShow(false);
    const handleShow = () => setShow(true);

    return (
        <>
            <Navbar fixed="top" bg="white" expand="lg" className="shadow-sm py-2">
                <Container>
                    <Navbar.Brand href="#home" className="d-flex align-items-center">
                        <img
                            alt=""
                            src="/logo.png"
                            height="70"
                            className="d-inline-block align-top"
                        />
                    </Navbar.Brand>
                    <Navbar.Toggle aria-controls="basic-navbar-nav" />
                    <Navbar.Collapse id="basic-navbar-nav" className="w-100">
                        <Nav className="mx-auto justify-content-center custom-nav text-center fw-medium">
                            <Nav.Link href="/" className="mx-3">Home</Nav.Link>
                            <Nav.Link href="/#About" className="mx-3">About Us</Nav.Link>
                            <Nav.Link href="/#Contact" className="mx-3">Contact Us</Nav.Link>
                        </Nav>

                        {/* Button will exist in all modes but centered only on mobile */}
                        <Form className="d-flex justify-content-center d-lg-none"> {/* Only for mobile view */}
                            <Button variant="outline-primary"
                                onClick={() => window.open("https://play.google.com/store/apps/details?id=com.tourkokan&pcampaignid=web_share", "_blank")}
                            >
                                <img
                                    src="https://static-00.iconduck.com/assets.00/google-play-icon-239x256-sm9pj90g.png"
                                    alt="Play Store Logo"
                                    style={{ width: '20px', height: '20px', marginRight: '5px' }}
                                />
                                Download
                            </Button>
                        </Form>
                        <Form className="d-none d-lg-flex"> {/* Visible only on desktop */}
                            <Button variant="outline-primary"
                                onClick={() => window.open("https://play.google.com/store/apps/details?id=com.tourkokan&pcampaignid=web_share", "_blank")}
                                className="ms-3" // Margin start for desktop alignment
                            >
                                <img
                                    src="https://static-00.iconduck.com/assets.00/google-play-icon-239x256-sm9pj90g.png"
                                    alt="Play Store Logo"
                                    style={{ width: '20px', height: '20px', marginRight: '5px' }}
                                />
                                Download
                            </Button>
                        </Form>
                    </Navbar.Collapse>
                </Container>
            </Navbar>

            <Modal show={show} onHide={handleClose}>
                <Modal.Header closeButton>
                    <Modal.Title>Application Under Deployment Process...!</Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    <p>The application is currently under deployment. Please stay tuned for updates!</p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={handleClose}>
                        Close
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
}

export default NavigationBar;