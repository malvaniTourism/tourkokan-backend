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
            <Navbar bg="light" expand="lg" className="bg-body-tertiary">
                <Container>
                    <Navbar.Brand href="#home" style={{ display: "flex", alignItems: "center" }}>
                        <img
                            alt=""
                            src="/assets/corousel/logo.png"
                            width="70"
                            height="70"
                            className="d-inline-block align-top"
                        />{' '}
                        Tourkokan
                    </Navbar.Brand>
                    <Navbar.Toggle aria-controls="basic-navbar-nav" />
                    <Navbar.Collapse id="basic-navbar-nav">
                        <Nav className="me-auto">
                            <Nav.Link href="/">Home</Nav.Link>
                            <Nav.Link href="/#About">About Us</Nav.Link>
                            <Nav.Link href="/#Contact">Contact Us</Nav.Link>
                        </Nav>
                        <Form className="d-flex">
                            <Button variant="outline-primary" onClick={handleShow}>Download</Button>
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