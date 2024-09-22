import React, { useState } from 'react';
import { Form, Button, Container, Row, Col } from 'react-bootstrap';
import NavigationBar from '../components/Navbar';
import Footer from '../components/Footer';
import ReactQuill from 'react-quill';
import 'react-quill/dist/quill.snow.css'; // Import Quill styles

const labelStyle = { color: 'black' };

const Register = () => {
    const [formData, setFormData] = useState({
        name: '',
        user_id: '',
        tag_line: '',
        mr_tag_line: '',
        description: '',
        mr_description: '',
        domain_name: '',
        logo: null,
        icon: null,
        image: null,
        latitude: '',
        longitude: '',
        pin_code: '',
        speciality: '',
        rules: '',
        social_media: {
            facebook: '',
            twitter: '',
            instagram: '',
            linkedin: '',
            others: ''
        }
    });

    const [errors, setErrors] = useState({});

    const [step, setStep] = useState(1);
    const [userFormData, setUserFormData] = useState({
        name: '',
        email: '',
        mobile: '',
        referral_code: ''
    });
    const [otp, setOtp] = useState('');
    
    const handleChange = (e) => {
        const { name, value, type, checked, files } = e.target;
        setFormData({
            ...formData,
            [name]: type === 'checkbox' ? checked : type === 'file' ? files[0] : value
        });
    };

    const handleSocialMediaChange = (e) => {
        const { name, value } = e.target;
        setFormData({
            ...formData,
            social_media: {
                ...formData.social_media,
                [name]: value
            }
        });
    };

    const validate = () => {
        const newErrors = {};

        if (!formData.name) newErrors.name = 'Name is required';
        if (!formData.user_id) newErrors.user_id = 'User ID is required';
        if (!formData.tag_line) newErrors.tag_line = 'Tag Line is required';
        if (!formData.description) newErrors.description = 'Description is required';
        if (!formData.domain_name) newErrors.domain_name = 'Domain Name is required';
        if (!formData.latitude || isNaN(formData.latitude)) newErrors.latitude = 'Valid latitude is required';
        if (!formData.longitude || isNaN(formData.longitude)) newErrors.longitude = 'Valid longitude is required';
        if (!formData.pin_code || isNaN(formData.pin_code)) newErrors.pin_code = 'Valid pin code is required';

        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (validate()) {
            // Handle form submission
            console.log(formData);
        }
    };

    const handleSubmitRegistration = async (e) => {
        e.preventDefault();
        // Perform registration API call
        try {
            // await axios.post('/api/register', formData);
            setStep(2); // Move to OTP verification
        } catch (error) {
            // Handle error
            console.error(error);
        }
    };

    const handleSubmitOtp = async (e) => {
        e.preventDefault();
        // Perform OTP verification API call
        try {
            // await axios.post('/api/verify-otp', { otp });
            setStep(3); // Move to the main form
        } catch (error) {
            // Handle error
            console.error(error);
        }
    };

    return (
        <div>
            <Container className="my-5">
                {step === 1 && (
                    <Form onSubmit={handleSubmitRegistration} noValidate>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="name">
                                    <Form.Label style={labelStyle}>Name *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="name"
                                        value={formData.name}
                                        onChange={handleChange}
                                        required
                                    />
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="email">
                                    <Form.Label style={labelStyle}>Email *</Form.Label>
                                    <Form.Control
                                        type="email"
                                        name="email"
                                        value={formData.email}
                                        onChange={handleChange}
                                        required
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="mobile">
                                    <Form.Label style={labelStyle}>Mobile (optional)</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="mobile"
                                        value={formData.mobile}
                                        onChange={handleChange}
                                    />
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="referral_code">
                                    <Form.Label style={labelStyle}>Referral Code (optional)</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="referral_code"
                                        value={formData.referral_code}
                                        onChange={handleChange}
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Button type="submit" className="mt-3">Register</Button>
                    </Form>
                )}

                {step === 2 && (
                    <Form onSubmit={handleSubmitOtp} noValidate>
                        <Row>
                            <Col md={12}>
                                <Form.Group controlId="otp">
                                    <Form.Label style={labelStyle}>Enter OTP sent to your email</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="otp"
                                        value={otp}
                                        onChange={(e) => setOtp(e.target.value)}
                                        required
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Button type="submit" className="mt-3">Verify OTP</Button>
                    </Form>
                )}

                {step === 3 && (
                    <Form onSubmit={handleSubmit} noValidate>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="name">
                                    <Form.Label style={labelStyle}>Name *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="name"
                                        value={formData.name}
                                        onChange={handleChange}
                                        isInvalid={!!errors.name}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.name}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="user_id">
                                    <Form.Label style={labelStyle}>User ID *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="user_id"
                                        value={formData.user_id}
                                        onChange={handleChange}
                                        isInvalid={!!errors.user_id}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.user_id}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="tag_line">
                                    <Form.Label style={labelStyle}>Tag Line *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="tag_line"
                                        value={formData.tag_line}
                                        onChange={handleChange}
                                        isInvalid={!!errors.tag_line}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.tag_line}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="mr_tag_line">
                                    <Form.Label style={labelStyle}>Marathi Tag Line</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="mr_tag_line"
                                        value={formData.mr_tag_line}
                                        onChange={handleChange}
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="description">
                                    <Form.Label style={labelStyle}>Description *</Form.Label>
                                    <Form.Control
                                        as="textarea"
                                        name="description"
                                        value={formData.description}
                                        onChange={handleChange}
                                        isInvalid={!!errors.description}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.description}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="mr_description">
                                    <Form.Label style={labelStyle}>Marathi Description</Form.Label>
                                    <Form.Control
                                        as="textarea"
                                        name="mr_description"
                                        value={formData.mr_description}
                                        onChange={handleChange}
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="domain_name">
                                    <Form.Label style={labelStyle}>Domain Name *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="domain_name"
                                        value={formData.domain_name}
                                        onChange={handleChange}
                                        isInvalid={!!errors.domain_name}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.domain_name}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="logo">
                                    <Form.Label style={labelStyle}>Logo</Form.Label>
                                    <Form.Control
                                        type="file"
                                        name="logo"
                                        onChange={handleChange}
                                        accept="image/*"
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="icon">
                                    <Form.Label style={labelStyle}>Icon</Form.Label>
                                    <Form.Control
                                        type="file"
                                        name="icon"
                                        onChange={handleChange}
                                        accept="image/*"
                                    />
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="image">
                                    <Form.Label style={labelStyle}>Image</Form.Label>
                                    <Form.Control
                                        type="file"
                                        name="image"
                                        onChange={handleChange}
                                        accept="image/*"
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="latitude">
                                    <Form.Label style={labelStyle}>Latitude *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="latitude"
                                        value={formData.latitude}
                                        onChange={handleChange}
                                        isInvalid={!!errors.latitude}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.latitude}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="longitude">
                                    <Form.Label style={labelStyle}>Longitude *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="longitude"
                                        value={formData.longitude}
                                        onChange={handleChange}
                                        isInvalid={!!errors.longitude}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.longitude}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="pin_code">
                                    <Form.Label style={labelStyle}>Pin Code *</Form.Label>
                                    <Form.Control
                                        type="text"
                                        name="pin_code"
                                        value={formData.pin_code}
                                        onChange={handleChange}
                                        isInvalid={!!errors.pin_code}
                                    />
                                    <Form.Control.Feedback type="invalid">
                                        {errors.pin_code}
                                    </Form.Control.Feedback>
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={6}>
                                <Form.Group controlId="speciality">
                                    <Form.Label style={labelStyle}>Speciality</Form.Label>
                                    <ReactQuill
                                        name="speciality"
                                        value={formData.speciality}
                                        onChange={(value) => setFormData({ ...formData, speciality: value })}
                                    />
                                </Form.Group>
                            </Col>
                            <Col md={6}>
                                <Form.Group controlId="rules">
                                    <Form.Label style={labelStyle}>Rules</Form.Label>
                                    <ReactQuill
                                        name="rules"
                                        value={formData.rules}
                                        onChange={(value) => setFormData({ ...formData, rules: value })}
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Row>
                            <Col md={12}>
                                <Form.Group controlId="social_media">
                                    <Form.Label style={labelStyle}>Social Media</Form.Label>
                                    <Form.Control
                                        type="text"
                                        placeholder="Facebook URL"
                                        name="facebook"
                                        value={formData.social_media.facebook}
                                        onChange={handleSocialMediaChange}
                                    />
                                    <Form.Control
                                        type="text"
                                        placeholder="Twitter URL"
                                        name="twitter"
                                        value={formData.social_media.twitter}
                                        onChange={handleSocialMediaChange}
                                    />
                                    <Form.Control
                                        type="text"
                                        placeholder="Instagram URL"
                                        name="instagram"
                                        value={formData.social_media.instagram}
                                        onChange={handleSocialMediaChange}
                                    />
                                    <Form.Control
                                        type="text"
                                        placeholder="LinkedIn URL"
                                        name="linkedin"
                                        value={formData.social_media.linkedin}
                                        onChange={handleSocialMediaChange}
                                    />
                                    <Form.Control
                                        type="text"
                                        placeholder="Others"
                                        name="others"
                                        value={formData.social_media.others}
                                        onChange={handleSocialMediaChange}
                                    />
                                </Form.Group>
                            </Col>
                        </Row>
                        <Button variant="primary" type="submit">
                            Submit
                        </Button>
                    </Form>
                )}
            </Container>
        </div>
    );
};

export default Register;
