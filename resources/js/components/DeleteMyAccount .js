import React, { useState } from 'react';
import { Form, Button, Container, Modal, Spinner, Row, Col } from 'react-bootstrap';

const DeleteMyAccount = () => {
    const [email, setEmail] = useState('');
    const [otp, setOtp] = useState('');
    const [showOtpModal, setShowOtpModal] = useState(false);
    const [showErrorModal, setShowErrorModal] = useState(false);
    const [errors, setErrors] = useState({});
    const [responseMessage, setResponseMessage] = useState('');
    const [loading, setLoading] = useState(false);

    const validateEmailForm = () => {
        const newErrors = {};
        if (!email.trim()) {
            newErrors.email = 'Email is required';
        } else if (!/\S+@\S+\.\S+/.test(email)) {
            newErrors.email = 'Email is invalid';
        }
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleDeleteRequest = async (e) => {
        e.preventDefault();
        if (!validateEmailForm()) return;
        setLoading(true);

        try {
            const response = await fetch('/api/v2/deleteMyAccount', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email }),
            });

            const responseData = await response.json();

            if (response.ok && responseData.success) {
                setResponseMessage(responseData.message);
                setShowOtpModal(true);
            } else {
                if (responseData.message && typeof responseData.message === 'object') {
                    const serverErrors = {};
                    for (const key in responseData.message) {
                        serverErrors[key] = responseData.message[key][0];
                    }
                    setErrors(serverErrors);
                } else {
                    setResponseMessage(responseData.message || 'Failed to process your request. Please try again later.');
                    setShowErrorModal(true);
                }
            }
        } catch (error) {
            console.error('Error:', error);
            setResponseMessage('Failed to process your request. Please try again later.');
            setShowErrorModal(true);
        } finally {
            setLoading(false);
        }
    };

    const validateOtpForm = () => {
        if (!/^\d{6}$/.test(otp)) {
            setErrors({ otp: 'OTP must be a 6-digit numeric code' });
            return false;
        }
        return true;
    };

    const handleVerifyOtp = async (e) => {
        e.preventDefault();
        if (!validateOtpForm()) return;
        setLoading(true);

        try {
            const response = await fetch('/api/v2/auth/verifyOtp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ delete: 1, email, otp }),
            });

            const responseData = await response.json();

            if (response.ok && responseData.success) {
                setResponseMessage(responseData.message);
                setShowOtpModal(false);
                setEmail('');
                alert(`${responseData.message}\n\nThank you for using our app. We hope to see you again soon!`);
            } else {
                setResponseMessage(responseData.message || 'Invalid OTP. Please try again.');
                setShowErrorModal(true);
            }
        } catch (error) {
            console.error('Error:', error);
            setResponseMessage('Failed to verify OTP. Please try again later.');
            setShowErrorModal(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{ padding: "60px 0", backgroundColor: "#f8f9fa" }}>
            <Container>
                <Row className="justify-content-center">
                    <Col xs={12} sm={10} md={8} lg={6} className="text-center">
                        <h2 className="mb-3">Delete Your Account</h2>
                        <p className="text-muted mb-4">
                            We're sorry to see you go. If you're sure you want to delete your account,
                            please enter your email address below. An OTP will be sent to your email for verification.
                            After verifying, your account will be permanently deleted. This action cannot be undone.
                        </p>
                        <Form onSubmit={handleDeleteRequest} className="text-start">
                            <Form.Group controlId="formEmail" className="mb-3">
                                <Form.Label>Email Address</Form.Label>
                                <Form.Control
                                    type="email"
                                    placeholder="Enter your email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    isInvalid={!!errors.email}
                                    disabled={loading}
                                />
                                <Form.Control.Feedback type="invalid">{errors.email}</Form.Control.Feedback>
                            </Form.Group>
                            <div className="d-grid">
                                <Button variant="danger" type="submit" disabled={loading}>
                                    {loading ? <Spinner animation="border" size="sm" /> : 'Delete Account'}
                                </Button>
                            </div>
                        </Form>
                    </Col>
                </Row>
            </Container>

            <Modal show={showOtpModal} onHide={() => {}} backdrop="static" keyboard={false}>
                <Modal.Header>
                    <Modal.Title>Enter OTP</Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    <Form onSubmit={handleVerifyOtp}>
                        <Form.Group controlId="formOtp" className="mb-3">
                            <Form.Label>OTP sent to your email</Form.Label>
                            <Form.Control
                                type="text"
                                placeholder="Enter 6-digit OTP"
                                value={otp}
                                onChange={(e) => setOtp(e.target.value)}
                                isInvalid={!!errors.otp}
                                disabled={loading}
                            />
                            <Form.Control.Feedback type="invalid">{errors.otp}</Form.Control.Feedback>
                        </Form.Group>
                        <div className="d-grid">
                            <Button variant="primary" type="submit" disabled={loading}>
                                {loading ? <Spinner animation="border" size="sm" /> : 'Confirm Delete'}
                            </Button>
                        </div>
                    </Form>
                </Modal.Body>
            </Modal>

            <Modal show={showErrorModal} onHide={() => setShowErrorModal(false)}>
                <Modal.Header closeButton>
                    <Modal.Title>Error</Modal.Title>
                </Modal.Header>
                <Modal.Body>{responseMessage}</Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setShowErrorModal(false)} disabled={loading}>
                        Close
                    </Button>
                </Modal.Footer>
            </Modal>
        </div>
    );
};

export default DeleteMyAccount;
