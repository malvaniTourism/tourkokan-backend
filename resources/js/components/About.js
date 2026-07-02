import React from "react";
import { Container, Row, Col } from 'react-bootstrap';
import "./styles.css";

const features = [
    {
        title: "Discover Konkan",
        text: "Explore popular destinations like Devgad Beach, Malvan, Ratnagiri, and many hidden gems along the Konkan coast.",
    },
    {
        title: "Real-Time Updates",
        text: "Get real-time updates, user reviews, and interactive maps for the best possible travel experience.",
    },
    {
        title: "Bus Timetables",
        text: "Access the latest bus timetables and local event listings to plan your trip seamlessly.",
    },
    {
        title: "Community",
        text: "Join a community of explorers and share your Konkan travel stories with fellow adventurers.",
    },
];

const About = () => {
    return (
        <div style={{ backgroundColor: "white", padding: "60px 0" }}>
            <Container>
                <Row className="mb-5">
                    <Col>
                        <h1 className="text-center mb-3">About Us</h1>
                        <p className="text-center text-muted" style={{ maxWidth: 700, margin: "0 auto" }}>
                            Welcome to <strong>TourKokan</strong>, your ultimate guide to exploring the breathtaking
                            beauty and cultural richness of the Konkan region. Our app provides all the resources
                            you need to make your journey through Konkan an unforgettable experience.
                        </p>
                    </Col>
                </Row>

                <Row className="g-4 mb-5">
                    {features.map((feature, index) => (
                        <Col key={index} xs={12} sm={6} lg={3}>
                            <div className="about-feature-card">
                                <h5>{feature.title}</h5>
                                <p className="mb-0" style={{ fontSize: "14px" }}>{feature.text}</p>
                            </div>
                        </Col>
                    ))}
                </Row>

                <Row className="g-4">
                    <Col md={6}>
                        <h2>Why Choose TourKokan?</h2>
                        <p>TourKokan stands out for its comprehensive approach to travel guidance. Our app not only provides detailed information about tourist spots but also offers real-time updates, user reviews, and interactive maps. Whether you are looking for adventure, relaxation, or cultural immersion, TourKokan ensures you have the best resources at your fingertips.</p>
                    </Col>
                    <Col md={6}>
                        <h2>Our Commitment to You</h2>
                        <p>We are committed to enhancing your travel experience with reliable and up-to-date information. Our team continuously works to expand our database with new destinations, updated bus timetables, and the latest events in Konkan. Your satisfaction and enjoyment are our top priorities.</p>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default About;
