import React, { useState } from 'react';
import Container from 'react-bootstrap/Container';
import Navbar from 'react-bootstrap/Navbar';
import { Form, Button, Nav, Modal } from 'react-bootstrap';

function NavigationBar() {
    const [show, setShow] = useState(false);

    const handleClose = () => setShow(false);
    const handleShow = () => setShow(true);

    return (
        <>
            <Navbar className="bg-body-tertiary">
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
                    <div style={{ display: "flex", justifyContent: "space-evenly", width: "25vw" }}>
                        <Nav.Link href="/">Home</Nav.Link>
                        <Nav.Link href="/#About">About Us</Nav.Link>
                        <Nav.Link href="/#Contact">Contact Us</Nav.Link>
                    </div>

                    <Form className="d-flex">
                        <Button variant="outline-primary" onClick={handleShow}>Download</Button>
                    </Form>
                </Container>
            </Navbar >

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