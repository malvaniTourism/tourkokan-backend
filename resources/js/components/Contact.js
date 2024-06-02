import React, { useState } from 'react';
import { Form, Button, Container, Modal } from 'react-bootstrap';
import './styles.css'

const Contact = () => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [message, setMessage] = useState('');
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [showFailureModal, setShowFailureModal] = useState(false);
    const appUrl = process.env.APP_URL; // Accessing the base URL from environment variables

    const handleSubmit = async (e) => {
        e.preventDefault();

        try {
            console.log(appUrl);
            const response = await fetch(`${appUrl}/api/v2/addGuestQuery`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ name, email, phone, message })
            });

            if (response.ok) {
                // Handle success
                  // Reset form fields
                  setName('');
                  setEmail('');
                  setPhone('');
                  setMessage('');
  
                setShowSuccessModal(true);
            } else {
                // Handle failure
                setShowFailureModal(true);
            }
        } catch (error) {
            // Handle error
            console.error('Error:', error);
        }
    };

    return (
        <Container>
            <div style={{ borderRadius: 15, padding: 10, display: "flex", flexDirection: "column", justifyContent: "center", alignItems: "center" }}>
                <h1 style={{ color: "#fff" }}>Contact Us</h1>
                <Form onSubmit={handleSubmit} style={{ display: "flex", flexDirection: "column", justifyContent: "center" }}>
                    <Form.Group controlId="formName">
                        <Form.Label>Name</Form.Label>
                        <Form.Control
                            className='inpuField'
                            type="text"
                            placeholder="Enter your name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </Form.Group>

                    <Form.Group controlId="formEmail">
                        <Form.Label>Email</Form.Label>
                        <Form.Control
                            className='inpuField'
                            type="email"
                            placeholder="Enter your email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                        />
                    </Form.Group>

                    <Form.Group controlId="formPhone">
                        <Form.Label>Phone</Form.Label>
                        <Form.Control
                            className='inpuField'
                            type="phone"
                            placeholder="Enter your phone number"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                        />
                    </Form.Group>

                    <Form.Group controlId="formMessage">
                        <Form.Label>Message</Form.Label>
                        <Form.Control
                            className='inpuField'
                            as="textarea"
                            rows={3}
                            placeholder="Enter your message"
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                        />
                    </Form.Group>

                    <Button variant="primary" type="submit" style={{ marginTop: 20 }}>
                        Submit
                    </Button>
                </Form>
            </div>

            {/* Success Modal */}
            <Modal show={showSuccessModal} onHide={() => setShowSuccessModal(false)}>
                <Modal.Header closeButton>
                    <Modal.Title>Success</Modal.Title>
                </Modal.Header>
                <Modal.Body>Your message has been successfully sent.</Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setShowSuccessModal(false)}>
                        Close
                    </Button>
                </Modal.Footer>
            </Modal>

            {/* Failure Modal */}
            <Modal show={showFailureModal} onHide={() => setShowFailureModal(false)}>
                <Modal.Header closeButton>
                    <Modal.Title>Error</Modal.Title>
                </Modal.Header>
                <Modal.Body>Failed to send your message. Please try again later.</Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setShowFailureModal(false)}>
                        Close
                    </Button>
                </Modal.Footer>
            </Modal>
        </Container>
    )
}

export default Contact;
