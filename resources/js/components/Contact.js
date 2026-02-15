import React, { useState } from 'react';
import { Form, Button, Container, Modal, Row, Col, Card } from 'react-bootstrap';
import './styles.css';

const Contact = () => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [message, setMessage] = useState('');
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [showFailureModal, setShowFailureModal] = useState(false);
    const [errors, setErrors] = useState({});
    const appUrl = process.env.MIX_APP_URL; // Accessing the base URL from environment variables

    const validateForm = () => {
        const newErrors = {};

        if (!name.trim()) {
            newErrors.name = 'Name is required';
        }

        if (!message.trim()) {
            newErrors.message = 'Message is required';
        }

        if (!email.trim() && !phone.trim()) {
            newErrors.contact = 'Either email or phone is required';
        }

        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!validateForm()) {
            return; // If validation fails, don't proceed with submission
        }

        try {
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
            setShowFailureModal(true);
        }
    };

    return (
        <Container>
            <Row className="justify-content-center">
                <Col md={8} lg={6}>
                    <div className="text-center mb-4">
                        <h1 className="text-white fw-bold">Contact Us</h1>
                        <p className="text-white-50">Have questions? We'd love to hear from you.</p>
                    </div>
                    <Card className="shadow-lg border-0 rounded-4">
                        <Card.Body className="p-3 p-md-5">
                            <Form onSubmit={handleSubmit}>
                                <Form.Group controlId="formName" className="mb-3">
                                    <Form.Label>Name</Form.Label>
                                    <Form.Control
                                        type="text"
                                        placeholder="Enter your name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        isInvalid={!!errors.name}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.name}
                                    </Form.Control.Feedback>
                                </Form.Group>

                                <Form.Group controlId="formEmail" className="mb-3">
                                    <Form.Label>Email</Form.Label>
                                    <Form.Control
                                        type="email"
                                        placeholder="Enter your email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        isInvalid={!!errors.contact && !phone.trim()}
                                    />
                                </Form.Group>

                                <Form.Group controlId="formPhone" className="mb-3">
                                    <Form.Label>Phone</Form.Label>
                                    <Form.Control
                                        type="tel"
                                        placeholder="Enter your phone number"
                                        value={phone}
                                        onChange={(e) => setPhone(e.target.value)}
                                        isInvalid={!!errors.contact && !email.trim()}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.contact}
                                    </Form.Control.Feedback>
                                </Form.Group>

                                <Form.Group controlId="formMessage" className="mb-4">
                                    <Form.Label>Message</Form.Label>
                                    <Form.Control
                                        as="textarea"
                                        rows={4}
                                        placeholder="Enter your message"
                                        value={message}
                                        onChange={(e) => setMessage(e.target.value)}
                                        isInvalid={!!errors.message}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.message}
                                    </Form.Control.Feedback>
                                </Form.Group>

                                <Button variant="primary" type="submit" className="w-100 py-2 fw-bold">
                                    Submit Message
                                </Button>
                            </Form>
                        </Card.Body>
                    </Card>
                </Col>
            </Row>

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
    );
};

export default Contact;
