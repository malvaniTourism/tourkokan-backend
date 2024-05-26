import React from "react";
import Container from 'react-bootstrap/Container';

const About = () => {
    return (
        <div>
            <div class="jumbotron" style={{display: "flex", justifyContent: "center"}}>
                <img src='https://mdbootstrap.com/img/new/slides/041.webp' style={{ height: "90vh", width: "100%" }} className='img-fluid shadow-4' alt='...' />
                <Container style={{ position: "absolute", zIndex: "100", paddingTop: 100}}>
                    <h1>Hello, world!</h1>
                    <p>...</p>
                    <p><a class="btn btn-primary btn-lg" href="#" role="button">Learn more</a></p>
                </Container>
            </div>
        </div>
    )
}

export default About;