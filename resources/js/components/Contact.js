import React, { useState } from 'react';
import { Form, Button, Container } from 'react-bootstrap';
import './styles.css'

const Contact = () => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [message, setMessage] = useState('');

    const handleSubmit = (e) => {
        e.preventDefault();
        // Handle form submission
        console.log({ name, email, message });
        // Here you would typically send the data to a backend endpoint
    };
    return (
        <Container>
            <div style={{ borderRadius: 15, padding: 10, display: "flex", flexDirection: "column", justifyContent: "center", alignItems: "center"}}>
                <h1 style={{color: "#fff"}}>Contact Us</h1>
                <Form onSubmit={handleSubmit} style={{display: "flex", flexDirection: "column", justifyContent: "center"}}>
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
                            onChange={(e) => setEmail(e.target.value)}
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

                    <Button variant="primary" type="submit" style={{marginTop: 20}}>
                        Submit
                    </Button>
                </Form>
            </div>
        </Container>
    )
}

export default Contact;