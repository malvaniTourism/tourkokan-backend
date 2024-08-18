import React, { useState } from 'react';
import { Form, Button, Container, Modal, Spinner } from 'react-bootstrap';

const DeleteMyAccount = () => {
    const [email, setEmail] = useState('');
    const [otp, setOtp] = useState('');
    const [showOtpModal, setShowOtpModal] = useState(false);
    const [showErrorModal, setShowErrorModal] = useState(false);
    const [errors, setErrors] = useState({});
    const [responseMessage, setResponseMessage] = useState(''); // State for response message
    const [loading, setLoading] = useState(false); // State for handling loading
    const appUrl = process.env.MIX_APP_URL; // Accessing the base URL from environment variables

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

        if (!validateEmailForm()) {
            return; // If validation fails, don't proceed with submission
        }

        setLoading(true); // Start loading

        try {
            const response = await fetch(`${appUrl}/api/v2/deleteMyAccount`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email })
            });

            const responseData = await response.json(); // Parse the JSON response

            if (response.ok && responseData.success) {
                // Show OTP modal on success
                setResponseMessage(responseData.message); // Set the success message from the response
                setShowOtpModal(true);
            } else {
                // Handle failure
                if (responseData.message && typeof responseData.message === 'object') {
                    // Handle validation errors
                    const serverErrors = {};
                    for (const key in responseData.message) {
                        serverErrors[key] = responseData.message[key][0];
                    }
                    setErrors(serverErrors);
                } else {
                    // Handle other errors
                    setResponseMessage(responseData.message || 'Failed to process your request. Please try again later.');
                    setShowErrorModal(true);
                }
            }
        } catch (error) {
            // Handle error
            console.error('Error:', error);
            setResponseMessage('Failed to process your request. Please try again later.');
            setShowErrorModal(true);
        } finally {
            setLoading(false); // Stop loading
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

        if (!validateOtpForm()) {
            return; // If validation fails, don't proceed with submission
        }

        setLoading(true); // Start loading

        try {
            const response = await fetch(`${appUrl}/api/v2/auth/verifyOtp`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    delete: 1,
                    email, otp
                })
            });

            const responseData = await response.json(); // Parse the JSON response

            if (response.ok && responseData.success) {
                // Handle successful OTP verification
                setResponseMessage(responseData.message); // Set the success message from the response
                setShowOtpModal(false); // Close the OTP modal
                setEmail(''); // Reset the email input field
                alert(`${responseData.message}\n\nThank you for using our app. We hope to see you again soon!`);
            } else {
                // Handle OTP verification failure
                setResponseMessage(responseData.message || 'Invalid OTP. Please try again.');
                setShowErrorModal(true);
            }
        } catch (error) {
            // Handle error
            console.error('Error:', error);
            setResponseMessage('Failed to verify OTP. Please try again later.');
            setShowErrorModal(true);
        } finally {
            setLoading(false); // Stop loading
        }
    };

    return (
        <Container>
            <div style={{ borderRadius: 15, padding: 10, display: "flex", flexDirection: "column", justifyContent: "center", alignItems: "center" }}>
                <h1 style={{ color: "#fff" }}>Delete Account</h1>
                <h1 style={{ color: "#333", textAlign: "center", marginBottom: 20 }}>Delete Your Account</h1>
                <p style={{ textAlign: "center", marginBottom: 20, fontSize: '16px', color: '#666' }}>
                    We're sorry to see you go. If you're sure you want to delete your account, please enter your email address below.
                    An OTP (One-Time Password) will be sent to your email for verification. After verifying the OTP, your account will be permanently deleted.
                    Please note that this action cannot be undone.
                </p>
                <Form onSubmit={handleDeleteRequest} style={{ display: "flex", flexDirection: "column", justifyContent: "center" }}>
                    <Form.Group controlId="formEmail">
                        <Form.Label>Email</Form.Label>
                        <Form.Control
                            type="email"
                            placeholder="Enter your email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            isInvalid={!!errors.email}
                            disabled={loading} // Disable input when loading
                        />
                        <Form.Control.Feedback type="invalid">
                            {errors.email}
                        </Form.Control.Feedback>
                    </Form.Group>

                    <Button variant="danger" type="submit" style={{ marginTop: 20 }} disabled={loading}>
                        {loading ? <Spinner animation="border" size="sm" /> : 'Delete Account'}
                    </Button>
                </Form>
            </div>

            {/* OTP Modal */}
            <Modal show={showOtpModal} onHide={() => { }} backdrop="static" keyboard={false}>
                <Modal.Header>
                    <Modal.Title>Enter OTP</Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    <Form onSubmit={handleVerifyOtp} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                        <Form.Group controlId="formOtp" style={{ width: '100%' }}>
                            <Form.Label>OTP</Form.Label>
                            <Form.Control
                                type="text"
                                placeholder="Enter the OTP sent to your email"
                                value={otp}
                                onChange={(e) => setOtp(e.target.value)}
                                isInvalid={!!errors.otp}
                                disabled={loading} // Disable input when loading
                            />
                            <Form.Control.Feedback type="invalid">
                                {errors.otp}
                            </Form.Control.Feedback>
                        </Form.Group>
                        <Button
                            variant="primary"
                            type="submit"
                            style={{ marginTop: 20 }}
                            disabled={loading}
                        >
                            {loading ? <Spinner animation="border" size="sm" /> : 'Confirm Delete'}
                        </Button>
                    </Form>

                </Modal.Body>
            </Modal>

            {/* Error Modal */}
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
        </Container>
    );
};

export default DeleteMyAccount;
